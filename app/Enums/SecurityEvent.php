<?php

namespace App\Enums;

enum SecurityEvent: string
{
    case LoginSucceeded = 'login_succeeded';
    case LoginFailed = 'login_failed';
    case LoginLockout = 'login_lockout';
    case LoginFromNewDevice = 'login_from_new_device';
    case LoggedOut = 'logged_out';
    case OtherDeviceLoggedOut = 'other_device_logged_out';
    case SessionRevoked = 'session_revoked';

    case PasswordChanged = 'password_changed';
    case PasswordResetRequested = 'password_reset_requested';
    case PasswordReset = 'password_reset';

    case EmailVerified = 'email_verified';

    case TwoFactorEnabled = 'two_factor_enabled';
    case TwoFactorDisabled = 'two_factor_disabled';
    case TwoFactorChallenged = 'two_factor_challenged';
    case TwoFactorPassed = 'two_factor_passed';
    case TwoFactorFailed = 'two_factor_failed';
    case RecoveryCodeUsed = 'recovery_code_used';

    case CompanySwitched = 'company_switched';
    case CompanyMemberInvited = 'company_member_invited';
    case CompanyInvitationCancelled = 'company_invitation_cancelled';
    case CompanyMemberJoined = 'company_member_joined';
    case CompanyMemberRoleChanged = 'company_member_role_changed';
    case CompanyMemberRemoved = 'company_member_removed';

    case ApiKeyCreated = 'api_key_created';
    case ApiKeyUpdated = 'api_key_updated';
    case ApiKeyRotated = 'api_key_rotated';
    case ApiKeyRevoked = 'api_key_revoked';

    // Company inbound-email integration settings (Settings → Inbox email).
    // Enabling mints an ingest token; rotating invalidates the old forwarding
    // address. Both are admin-only config changes worth an audit trail — the
    // enabled/disabled state and (never the token itself) live in the metadata.
    case InboundEmailSettingChanged = 'inbound_email_setting_changed';
    case InboundEmailTokenRotated = 'inbound_email_token_rotated';

    // A user revoked an authorized OAuth application (e.g. an MCP client) from
    // their own Settings → Security → Authorized applications screen. The
    // revoked client id + name live in the metadata.
    case McpConnectionRevoked = 'mcp_connection_revoked';

    // An employee changed their own info (address / TD1 claim amounts) through
    // the self-service portal. The actor is a Contact, so user_id is null and
    // the contact id + changed fields live in the metadata.
    case EmployeePortalInfoUpdated = 'employee_portal_info_updated';

    // An employee set or changed their own portal sign-in password through the
    // self-service portal. The actor is a Contact, so user_id is null and the
    // contact id (plus whether this was first-time setup) live in the metadata.
    case EmployeePortalPasswordChanged = 'employee_portal_password_changed';

    // Site admin portal actions. For all of these the actor is a *different*
    // user than the subject, so user_id is the user the action was taken on and
    // the acting admin's email lives in the metadata under `actor`.
    case UserDisabled = 'user_disabled';
    case UserEnabled = 'user_enabled';
    case UserUpdatedByAdmin = 'user_updated_by_admin';

    // Company lifecycle from the site admin portal. SecurityLog::company_id is
    // derived from the acting admin's current company, which is not the target
    // here — the affected company's id/slug/name live in the metadata. For
    // CompanyPurged that metadata is the only surviving record of the company.
    case CompanyDeleted = 'company_deleted';
    case CompanyRestored = 'company_restored';
    case CompanyPurged = 'company_purged';
}
