<?php

declare(strict_types=1);

namespace Drupal\dbtng_migrator\Model;

/**
 * Stable machine-readable reasons that prevent controlled promotion.
 */
enum FailoverBlockerCode: string {
  case ProfileNotFull = 'profile_not_full';
  case CaptureNotInstalled = 'capture_not_installed';
  case CaptureUnhealthy = 'capture_unhealthy';
  case CaptureEngineMismatch = 'capture_engine_mismatch';
  case PendingEvents = 'pending_events';
  case ReconciliationNotMatch = 'reconciliation_not_match';
  case IntegrityFailed = 'integrity_failed';
  case ManifestInvalid = 'manifest_invalid';
  case ManifestProfileMismatch = 'manifest_profile_mismatch';
  case ManifestNotActivatable = 'manifest_not_activatable';
  case ManifestNotFullFidelity = 'manifest_not_full_fidelity';
  case ManifestNotPublished = 'manifest_not_published';
  case ManifestTopologyMismatch = 'manifest_topology_mismatch';
  case SchemaFingerprintMismatch = 'schema_fingerprint_mismatch';
}
