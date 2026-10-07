<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Failover;

use Drupal\dbtng_migrator\Contract\ChangeCaptureInterface;
use Drupal\dbtng_migrator\Contract\DatabaseTopologyResolverInterface;
use Drupal\dbtng_migrator\Contract\FailoverReadinessCheckerInterface;
use Drupal\dbtng_migrator\Contract\ReconciliationEngineInterface;
use Drupal\dbtng_migrator\Contract\StandbyManifestReaderInterface;
use Drupal\dbtng_migrator\Model\FailoverBlocker;
use Drupal\dbtng_migrator\Model\FailoverBlockerCode;
use Drupal\dbtng_migrator\Model\FailoverReadinessReport;
use Drupal\dbtng_migrator\Model\FailoverReadinessStatus;
use Drupal\dbtng_migrator\Model\ReplicationProfile;

/**
 * Combines existing read-only evidence into a failover readiness decision.
 */
final class FailoverReadinessChecker implements FailoverReadinessCheckerInterface {

  public function __construct(
    private readonly DatabaseTopologyResolverInterface $resolver,
    private readonly ChangeCaptureInterface $capture,
    private readonly ReconciliationEngineInterface $reconciliation,
    private readonly StandbyManifestReaderInterface $manifests,
  ) {}

  public function check(): FailoverReadinessReport {
    $topology = $this->resolver->resolve();
    $capture = $this->capture->status();
    $reconciliation = $this->reconciliation->reconcile();
    $manifest = $this->manifests->readCurrent($topology->standby->identity);
    $blockers = [];

    if ($topology->profile !== ReplicationProfile::Full) {
      $blockers[] = new FailoverBlocker(
        FailoverBlockerCode::ProfileNotFull,
        'The configured standby profile is not FULL and must not be promoted.',
      );
    }

    if (!$capture->installed) {
      $blockers[] = new FailoverBlocker(
        FailoverBlockerCode::CaptureNotInstalled,
        'Durable capture is not installed on the configured primary.',
      );
    }
    if (!$capture->healthy) {
      $blockers[] = new FailoverBlocker(
        FailoverBlockerCode::CaptureUnhealthy,
        'Durable capture on the configured primary is not healthy.',
      );
    }
    if ($capture->engine !== $topology->primary->engine) {
      $blockers[] = new FailoverBlocker(
        FailoverBlockerCode::CaptureEngineMismatch,
        'Capture health was reported for an engine different from the configured primary.',
      );
    }
    if ($capture->pendingEvents !== 0) {
      $blockers[] = new FailoverBlocker(
        FailoverBlockerCode::PendingEvents,
        sprintf('%d durable change event(s) remain pending.', $capture->pendingEvents),
      );
    }

    if (!$reconciliation->matches()) {
      $blockers[] = new FailoverBlocker(
        FailoverBlockerCode::ReconciliationNotMatch,
        sprintf('Read-only reconciliation result is %s, not MATCH.', $reconciliation->status->value),
      );
    }
    if (!$reconciliation->integrityPass) {
      $blockers[] = new FailoverBlocker(
        FailoverBlockerCode::IntegrityFailed,
        'Standby engine integrity validation did not pass.',
      );
    }
    if (!$reconciliation->manifestPass) {
      $blockers[] = new FailoverBlocker(
        FailoverBlockerCode::ManifestInvalid,
        'Reconciliation could not validate the published standby manifest.',
      );
    }

    $generationId = NULL;
    if ($manifest === NULL) {
      $blockers[] = new FailoverBlocker(
        FailoverBlockerCode::ManifestInvalid,
        'No valid current standby manifest is available.',
      );
    }
    else {
      $generationId = is_string($manifest['generation_id'] ?? NULL)
        ? $manifest['generation_id']
        : NULL;
      if (($manifest['profile'] ?? NULL) !== ReplicationProfile::Full->value) {
        $blockers[] = new FailoverBlocker(
          FailoverBlockerCode::ManifestProfileMismatch,
          'Published standby manifest does not describe a FULL profile.',
        );
      }
      if (($manifest['activatable'] ?? FALSE) !== TRUE) {
        $blockers[] = new FailoverBlocker(
          FailoverBlockerCode::ManifestNotActivatable,
          'Published standby manifest is not marked activatable.',
        );
      }
      if (($manifest['full_fidelity'] ?? FALSE) !== TRUE) {
        $blockers[] = new FailoverBlocker(
          FailoverBlockerCode::ManifestNotFullFidelity,
          'Published standby manifest is not marked full-fidelity.',
        );
      }
      if (($manifest['lifecycle_state'] ?? NULL) !== 'published') {
        $blockers[] = new FailoverBlocker(
          FailoverBlockerCode::ManifestNotPublished,
          'Standby generation is not recorded as published.',
        );
      }
      if (($manifest['primary_engine'] ?? NULL) !== $topology->primary->engine->value
        || ($manifest['standby_engine'] ?? NULL) !== $topology->standby->engine->value) {
        $blockers[] = new FailoverBlocker(
          FailoverBlockerCode::ManifestTopologyMismatch,
          'Published standby manifest does not match the current primary/standby engine direction.',
        );
      }
      $fingerprint = $manifest['schema_fingerprint'] ?? NULL;
      if (!is_string($fingerprint) || !hash_equals($reconciliation->schemaFingerprint, $fingerprint)) {
        $blockers[] = new FailoverBlocker(
          FailoverBlockerCode::SchemaFingerprintMismatch,
          'Published standby baseline schema fingerprint does not match the reconciled primary schema.',
        );
      }
    }

    $blockers = $this->uniqueBlockers($blockers);
    return new FailoverReadinessReport(
      $blockers === [] ? FailoverReadinessStatus::Ready : FailoverReadinessStatus::NotReady,
      $topology->primary->label(),
      $topology->standby->label(),
      $topology->profile,
      $capture->healthy,
      $capture->pendingEvents,
      $reconciliation->status,
      $reconciliation->integrityPass,
      $reconciliation->manifestPass,
      $generationId,
      $blockers,
    );
  }

  /**
   * Removes duplicate blocker codes while preserving first evidence.
   *
   * @param list<FailoverBlocker> $blockers
   *   Possibly repeated blockers from independent evidence sources.
   *
   * @return list<FailoverBlocker>
   *   Stable first occurrence of each blocker code.
   */
  private function uniqueBlockers(array $blockers): array {
    $unique = [];
    foreach ($blockers as $blocker) {
      $unique[$blocker->code->value] ??= $blocker;
    }
    return array_values($unique);
  }

}
