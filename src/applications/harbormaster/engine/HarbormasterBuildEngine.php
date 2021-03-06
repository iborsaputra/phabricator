<?php

/**
 * Moves a build forward by queuing build tasks, canceling or restarting the
 * build, or failing it in response to task failures.
 */
final class HarbormasterBuildEngine extends Phobject {

  private $build;
  private $viewer;
  private $newBuildTargets = array();
  private $artifactReleaseQueue = array();
  private $forceBuildableUpdate;

  public function setForceBuildableUpdate($force_buildable_update) {
    $this->forceBuildableUpdate = $force_buildable_update;
    return $this;
  }

  public function shouldForceBuildableUpdate() {
    return $this->forceBuildableUpdate;
  }

  public function queueNewBuildTarget(HarbormasterBuildTarget $target) {
    $this->newBuildTargets[] = $target;
    return $this;
  }

  public function getNewBuildTargets() {
    return $this->newBuildTargets;
  }

  public function setViewer(PhabricatorUser $viewer) {
    $this->viewer = $viewer;
    return $this;
  }

  public function getViewer() {
    return $this->viewer;
  }

  public function setBuild(HarbormasterBuild $build) {
    $this->build = $build;
    return $this;
  }

  public function getBuild() {
    return $this->build;
  }

  public function continueBuild() {
    $build = $this->getBuild();

    $lock_key = 'harbormaster.build:'.$build->getID();
    $lock = PhabricatorGlobalLock::newLock($lock_key)->lock(15);

    $build->reload();
    $old_status = $build->getBuildStatus();

    try {
      $this->updateBuild($build);
    } catch (Exception $ex) {
      // If any exception is raised, the build is marked as a failure and the
      // exception is re-thrown (this ensures we don't leave builds in an
      // inconsistent state).
      $build->setBuildStatus(HarbormasterBuildStatus::STATUS_ERROR);
      $build->save();

      $lock->unlock();

      $this->releaseAllArtifacts($build);

      throw $ex;
    }

    $lock->unlock();

    // NOTE: We queue new targets after releasing the lock so that in-process
    // execution via `bin/harbormaster` does not reenter the locked region.
    foreach ($this->getNewBuildTargets() as $target) {
      $task = PhabricatorWorker::scheduleTask(
        'HarbormasterTargetWorker',
        array(
          'targetID' => $target->getID(),
        ),
        array(
          'objectPHID' => $target->getPHID(),
        ));
    }

    // If the build changed status, we might need to update the overall status
    // on the buildable.
    $new_status = $build->getBuildStatus();
    if ($new_status != $old_status || $this->shouldForceBuildableUpdate()) {
      $this->updateBuildable($build->getBuildable());
    }

    $this->releaseQueuedArtifacts();

    // If we are no longer building for any reason, release all artifacts.
    if (!$build->isBuilding()) {
      $this->releaseAllArtifacts($build);
    }
  }

  private function updateBuild(HarbormasterBuild $build) {
    if ($build->isAborting()) {
      $this->releaseAllArtifacts($build);
      $build->setBuildStatus(HarbormasterBuildStatus::STATUS_ABORTED);
      $build->save();
    }

    if (($build->getBuildStatus() == HarbormasterBuildStatus::STATUS_PENDING) ||
        ($build->isRestarting())) {
      $this->restartBuild($build);
      $build->setBuildStatus(HarbormasterBuildStatus::STATUS_BUILDING);
      $build->save();
    }

    if ($build->isResuming()) {
      $build->setBuildStatus(HarbormasterBuildStatus::STATUS_BUILDING);
      $build->save();
    }

    if ($build->isPausing() && !$build->isComplete()) {
      $build->setBuildStatus(HarbormasterBuildStatus::STATUS_PAUSED);
      $build->save();
    }

    $build->deleteUnprocessedCommands();

    if ($build->getBuildStatus() == HarbormasterBuildStatus::STATUS_BUILDING) {
      $this->updateBuildSteps($build);
    }
  }

  private function restartBuild(HarbormasterBuild $build) {

    // We're restarting the build, so release all previous artifacts.
    $this->releaseAllArtifacts($build);

    // Increment the build generation counter on the build.
    $build->setBuildGeneration($build->getBuildGeneration() + 1);

    // Currently running targets should periodically check their build
    // generation (which won't have changed) against the build's generation.
    // If it is different, they will automatically stop what they're doing
    // and abort.

    // Previously we used to delete targets, logs and artifacts here.  Instead
    // leave them around so users can view previous generations of this build.
  }

  private function updateBuildSteps(HarbormasterBuild $build) {
    $all_targets = id(new HarbormasterBuildTargetQuery())
      ->setViewer($this->getViewer())
      ->withBuildPHIDs(array($build->getPHID()))
      ->withBuildGenerations(array($build->getBuildGeneration()))
      ->execute();

    $this->updateWaitingTargets($all_targets);

    $targets = mgroup($all_targets, 'getBuildStepPHID');

    $steps = id(new HarbormasterBuildStepQuery())
      ->setViewer($this->getViewer())
      ->withBuildPlanPHIDs(array($build->getBuildPlan()->getPHID()))
      ->execute();
    $steps = mpull($steps, null, 'getPHID');

    // Identify steps which are in various states.

    $queued = array();
    $underway = array();
    $waiting = array();
    $complete = array();
    $failed = array();
    foreach ($steps as $step) {
      $step_targets = idx($targets, $step->getPHID(), array());

      if ($step_targets) {
        $is_queued = false;

        $is_underway = false;
        foreach ($step_targets as $target) {
          if ($target->isUnderway()) {
            $is_underway = true;
            break;
          }
        }

        $is_waiting = false;
        foreach ($step_targets as $target) {
          if ($target->isWaiting()) {
            $is_waiting = true;
            break;
          }
        }

        $is_complete = true;
        foreach ($step_targets as $target) {
          if (!$target->isComplete()) {
            $is_complete = false;
            break;
          }
        }

        $is_failed = false;
        foreach ($step_targets as $target) {
          if ($target->isFailed()) {
            $is_failed = true;
            break;
          }
        }
      } else {
        $is_queued = true;
        $is_underway = false;
        $is_waiting = false;
        $is_complete = false;
        $is_failed = false;
      }

      if ($is_queued) {
        $queued[$step->getPHID()] = true;
      }

      if ($is_underway) {
        $underway[$step->getPHID()] = true;
      }

      if ($is_waiting) {
        $waiting[$step->getPHID()] = true;
      }

      if ($is_complete) {
        $complete[$step->getPHID()] = true;
      }

      if ($is_failed) {
        $failed[$step->getPHID()] = true;
      }
    }

    // If any step failed, fail the whole build, then bail.
    if (count($failed)) {
      $build->setBuildStatus(HarbormasterBuildStatus::STATUS_FAILED);
      $build->save();
      return;
    }

    // If every step is complete, we're done with this build. Mark it passed
    // and bail.
    if (count($complete) == count($steps)) {
      $build->setBuildStatus(HarbormasterBuildStatus::STATUS_PASSED);
      $build->save();
      return;
    }

    // Release any artifacts which are not inputs to any remaining build
    // step. We're done with these, so something else is free to use them.
    $ongoing_phids = array_keys($queued + $waiting + $underway);
    $ongoing_steps = array_select_keys($steps, $ongoing_phids);
    $this->releaseUnusedArtifacts($all_targets, $ongoing_steps);

    // Identify all the steps which are ready to run (because all their
    // dependencies are complete).

    $runnable = array();
    foreach ($steps as $step) {
      $dependencies = $step->getStepImplementation()->getDependencies($step);

      if (isset($queued[$step->getPHID()])) {
        $can_run = true;
        foreach ($dependencies as $dependency) {
          if (empty($complete[$dependency])) {
            $can_run = false;
            break;
          }
        }

        if ($can_run) {
          $runnable[] = $step;
        }
      }
    }

    if (!$runnable && !$waiting && !$underway) {
      // This means the build is deadlocked, and the user has configured
      // circular dependencies.
      $build->setBuildStatus(HarbormasterBuildStatus::STATUS_DEADLOCKED);
      $build->save();
      return;
    }

    foreach ($runnable as $runnable_step) {
      $target = HarbormasterBuildTarget::initializeNewBuildTarget(
        $build,
        $runnable_step,
        $build->retrieveVariablesFromBuild());
      $target->save();

      $this->queueNewBuildTarget($target);
    }
  }


  /**
   * Release any artifacts which aren't used by any running or waiting steps.
   *
   * This releases artifacts as soon as they're no longer used. This can be
   * particularly relevant when a build uses multiple hosts since it returns
   * hosts to the pool more quickly.
   *
   * @param list<HarbormasterBuildTarget> Targets in the build.
   * @param list<HarbormasterBuildStep> List of running and waiting steps.
   * @return void
   */
  private function releaseUnusedArtifacts(array $targets, array $steps) {
    assert_instances_of($targets, 'HarbormasterBuildTarget');
    assert_instances_of($steps, 'HarbormasterBuildStep');

    if (!$targets || !$steps) {
      return;
    }

    $target_phids = mpull($targets, 'getPHID');

    $artifacts = id(new HarbormasterBuildArtifactQuery())
      ->setViewer($this->getViewer())
      ->withBuildTargetPHIDs($target_phids)
      ->withIsReleased(false)
      ->execute();
    if (!$artifacts) {
      return;
    }

    // Collect all the artifacts that remaining build steps accept as inputs.
    $must_keep = array();
    foreach ($steps as $step) {
      $inputs = $step->getStepImplementation()->getArtifactInputs();
      foreach ($inputs as $input) {
        $artifact_key = $input['key'];
        $must_keep[$artifact_key] = true;
      }
    }

    // Queue unreleased artifacts which no remaining step uses for immediate
    // release.
    foreach ($artifacts as $artifact) {
      $key = $artifact->getArtifactKey();
      if (isset($must_keep[$key])) {
        continue;
      }

      $this->artifactReleaseQueue[] = $artifact;
    }
  }


  /**
   * Process messages which were sent to these targets, kicking applicable
   * targets out of "Waiting" and into either "Passed" or "Failed".
   *
   * @param list<HarbormasterBuildTarget> List of targets to process.
   * @return void
   */
  private function updateWaitingTargets(array $targets) {
    assert_instances_of($targets, 'HarbormasterBuildTarget');

    // We only care about messages for targets which are actually in a waiting
    // state.
    $waiting_targets = array();
    foreach ($targets as $target) {
      if ($target->isWaiting()) {
        $waiting_targets[$target->getPHID()] = $target;
      }
    }

    if (!$waiting_targets) {
      return;
    }

    $messages = id(new HarbormasterBuildMessageQuery())
      ->setViewer($this->getViewer())
      ->withBuildTargetPHIDs(array_keys($waiting_targets))
      ->withConsumed(false)
      ->execute();

    foreach ($messages as $message) {
      $target = $waiting_targets[$message->getBuildTargetPHID()];

      switch ($message->getType()) {
        case HarbormasterMessageType::MESSAGE_PASS:
          $new_status = HarbormasterBuildTarget::STATUS_PASSED;
          break;
        case HarbormasterMessageType::MESSAGE_FAIL:
          $new_status = HarbormasterBuildTarget::STATUS_FAILED;
          break;
        case HarbormasterMessageType::MESSAGE_WORK:
        default:
          $new_status = null;
          break;
      }

      if ($new_status !== null) {
        $message->setIsConsumed(true);
        $message->save();

        $target->setTargetStatus($new_status);

        if ($target->isComplete()) {
          $target->setDateCompleted(PhabricatorTime::getNow());
        }

        $target->save();
      }
    }
  }


  /**
   * Update the overall status of the buildable this build is attached to.
   *
   * After a build changes state (for example, passes or fails) it may affect
   * the overall state of the associated buildable. Compute the new aggregate
   * state and save it on the buildable.
   *
   * @param   HarbormasterBuild The buildable to update.
   * @return  void
   */
  private function updateBuildable(HarbormasterBuildable $buildable) {
    $viewer = $this->getViewer();

    $lock_key = 'harbormaster.buildable:'.$buildable->getID();
    $lock = PhabricatorGlobalLock::newLock($lock_key)->lock(15);

    $buildable = id(new HarbormasterBuildableQuery())
      ->setViewer($viewer)
      ->withIDs(array($buildable->getID()))
      ->needBuilds(true)
      ->executeOne();

    $all_pass = true;
    $any_fail = false;
    foreach ($buildable->getBuilds() as $build) {
      if ($build->getBuildStatus() != HarbormasterBuildStatus::STATUS_PASSED) {
        $all_pass = false;
      }
      if (in_array($build->getBuildStatus(), array(
          HarbormasterBuildStatus::STATUS_FAILED,
          HarbormasterBuildStatus::STATUS_ERROR,
          HarbormasterBuildStatus::STATUS_DEADLOCKED,
        ))) {

        $any_fail = true;
      }
    }

    if ($any_fail) {
      $new_status = HarbormasterBuildable::STATUS_FAILED;
    } else if ($all_pass) {
      $new_status = HarbormasterBuildable::STATUS_PASSED;
    } else {
      $new_status = HarbormasterBuildable::STATUS_BUILDING;
    }

    $old_status = $buildable->getBuildableStatus();
    $did_update = ($old_status != $new_status);
    if ($did_update) {
      $buildable->setBuildableStatus($new_status);
      $buildable->save();
    }

    $lock->unlock();

    // If we changed the buildable status, try to post a transaction to the
    // object about it. We can safely do this outside of the locked region.

    // NOTE: We only post transactions for automatic buildables, not for
    // manual ones: manual builds are test builds, whoever is doing tests
    // can look at the results themselves, and other users generally don't
    // care about the outcome.

    $should_publish = $did_update &&
                      $new_status != HarbormasterBuildable::STATUS_BUILDING &&
                      !$buildable->getIsManualBuildable();

    if (!$should_publish) {
      return;
    }

    $object = id(new PhabricatorObjectQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($buildable->getBuildablePHID()))
      ->executeOne();
    if (!$object) {
      return;
    }

    $publish_phid = $object->getHarbormasterPublishablePHID();
    if (!$publish_phid) {
      return;
    }

    if ($publish_phid === $object->getPHID()) {
      $publish = $object;
    } else {
      $publish = id(new PhabricatorObjectQuery())
        ->setViewer($viewer)
        ->withPHIDs(array($publish_phid))
        ->executeOne();
      if (!$publish) {
        return;
      }
    }

    if (!($publish instanceof PhabricatorApplicationTransactionInterface)) {
      return;
    }

    $template = $publish->getApplicationTransactionTemplate();
    if (!$template) {
      return;
    }

    $template
      ->setTransactionType(PhabricatorTransactions::TYPE_BUILDABLE)
      ->setMetadataValue(
        'harbormaster:buildablePHID',
        $buildable->getPHID())
      ->setOldValue($old_status)
      ->setNewValue($new_status);

    $harbormaster_phid = id(new PhabricatorHarbormasterApplication())
      ->getPHID();

    $daemon_source = PhabricatorContentSource::newForSource(
      PhabricatorDaemonContentSource::SOURCECONST);

    $editor = $publish->getApplicationTransactionEditor()
      ->setActor($viewer)
      ->setActingAsPHID($harbormaster_phid)
      ->setContentSource($daemon_source)
      ->setContinueOnNoEffect(true)
      ->setContinueOnMissingFields(true);

    $editor->applyTransactions(
      $publish->getApplicationTransactionObject(),
      array($template));
  }

  private function releaseAllArtifacts(HarbormasterBuild $build) {
    $targets = id(new HarbormasterBuildTargetQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withBuildPHIDs(array($build->getPHID()))
      ->withBuildGenerations(array($build->getBuildGeneration()))
      ->execute();

    if (count($targets) === 0) {
      return;
    }

    $target_phids = mpull($targets, 'getPHID');

    $artifacts = id(new HarbormasterBuildArtifactQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withBuildTargetPHIDs($target_phids)
      ->withIsReleased(false)
      ->execute();
    foreach ($artifacts as $artifact) {
      $artifact->releaseArtifact();
    }
  }

  private function releaseQueuedArtifacts() {
    foreach ($this->artifactReleaseQueue as $key => $artifact) {
      $artifact->releaseArtifact();
      unset($this->artifactReleaseQueue[$key]);
    }
  }

}
