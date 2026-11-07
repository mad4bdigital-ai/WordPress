# FAORE Data Model

- **FleetCompany**: company_id, enrollment state, supported modes, local controller identity.
- **CompanyEnvironment**: company_id + environment + site_uuid + registered origin; one independently verified binding per environment.
- **CapabilityManifest**: company/environment, manifest version, capabilities, providers, supported actions, health and skill versions. Discovery does not grant admission.
- **ParentOperation**: parent ID, requester, requested capability, targeted companies, plan and aggregate outcome.
- **ChildOperation**: child ID, parent ID, exact company/environment/site binding, scope digest, local admission, state and evidence reference.
- **DeliveryAttempt**: delivery_id, logical child ID, message ID, sequence, ACK, retry count and dead-letter reason. A new delivery is not a new effect.
- **AdmissionDecision**: accepted/rejected, local reason code, evaluated scope and policy reference. A refusal cannot be overridden centrally.
- **LocalJournal / Receipt**: immutable transition, Broker stage (305/306/312/313), local result/readback digest, generation and evidence status.
- **RuntimeGeneration**: canonical source digest, current/desired/rollback version, compatibility and company-owned overlay.
- **DependencyEdge**: Component/Company/Fleet nodes and impact type Runtime/Schema/Data/Presentation/Security.
- **FleetReport**: per-child status, evidence grade, error, age, drift summary and receipt references; no secrets or customer data.

## State semantics
Transport is at-least-once; effects are idempotent. Child transitions: PLANNED -> QUEUED -> ADMITTED -> RUNNING -> COMPLETED/FAILED/ROLLED_BACK. QUEUED -> REJECTED terminates the original scope. Evidence grades Observed, Verified, Unverified and Stale are separate from job status. Verified requires a matching original receipt and independent readback.
