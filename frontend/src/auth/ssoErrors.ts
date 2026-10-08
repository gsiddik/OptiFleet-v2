/** Error codes the backend puts in `?sso_error=` when "Sign in with OptiNexus" fails → the message key to show. */
const SSO_ERROR_KEYS: Record<string, string> = {
  access_denied: 'auth.ssoErrors.accessDenied',
  no_membership: 'auth.ssoErrors.accessDenied',
  user_inactive: 'auth.ssoErrors.accessDenied',
  tenant_not_linked: 'auth.ssoErrors.tenantNotLinked',
  tenant_inactive: 'auth.ssoErrors.tenantNotLinked',
  user_not_provisioned: 'auth.ssoErrors.userNotProvisioned',
  email_not_verified: 'auth.ssoErrors.emailNotVerified',
  account_conflict: 'auth.ssoErrors.accountConflict',
};

export function ssoErrorKey(code: string | null): string | null {
  if (!code) return null;
  return SSO_ERROR_KEYS[code] ?? 'auth.ssoErrors.failed';
}
