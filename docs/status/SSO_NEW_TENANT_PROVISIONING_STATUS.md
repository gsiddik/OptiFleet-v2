# SSO new customer tenant provisioning

Baseline: main e9b2c85f7e7e6dff193d0781753e94b06b6af0b6.
Branch: fix/sso-new-tenant-provisioning.

## Confirmed incident

An ACTIVE Fleet tenant is linked to one Nexus tenant. The affected login belongs to a different, newly created customer and tenant. Assigning Fleet in Nexus did not create its Fleet projection; SSO refused with tenant_not_linked before user provisioning. Password activation is not evidence of a relinking operation.

## Implementation checkpoint

- New tenant projections require fresh product-context approval for the verified OIDC subject and tenant ID, plus ACTIVE commercial context with Fleet application entitlement.
- Names and codes come from the server-to-server product context. No email/name/code matching to adopt an existing tenant. Code collisions use the Nexus UUID.
- New projections are ACTIVE/CENTRAL, with audited creation and no local role grants. Existing LOCAL tenants stay LOCAL; disabled/deleted tenants are not revived.
- Tenant, user and membership resolution is transactional. Unique Nexus ID handles concurrent tenant inserts without rebinding a customer.
- OPTINEXUS_SSO_PROVISION_TENANTS defaults true while the integration itself remains off by default; false retains manual provisioning.
- Regression tests cover separate customer data isolation, repeat login, code collision, identity conflict rollback, disabled/deleted tenants and upstream denial/outage.

Validation: pending CI. PHP is unavailable locally; no runtime installer was used. No customer data or deployment changed. Merge requires owner instruction.
