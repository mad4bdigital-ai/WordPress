export function providerExecutionPlan(definition, caseCount, options = {}) {
  const estimatedCaseSeconds = Math.max(1, Number(options.estimatedCaseSeconds || 45));
  const reserveSeconds = Math.max(0, Number(options.reserveSeconds || 30));
  const sessionStartOverheadSeconds = Math.max(0, Number(options.sessionStartOverheadSeconds || 10));
  const constraints = definition?.runtime_constraints || {};
  const mode = String(constraints.session_limit_mode || "unknown");
  const hardSessionSeconds = Number(constraints.hard_session_seconds || 0);
  const chunking = String(constraints.case_chunking || "not_required");

  let casesPerSession = Math.max(1, caseCount);
  if (chunking === "one_case_per_session") {
    casesPerSession = 1;
  } else if (mode === "hard" && hardSessionSeconds > 0) {
    const usable = Math.max(estimatedCaseSeconds, hardSessionSeconds - reserveSeconds);
    casesPerSession = Math.max(1, Math.floor(usable / estimatedCaseSeconds));
  }

  const sessionsRequired = Math.max(1, Math.ceil(Math.max(1, caseCount) / casesPerSession));
  return {
    case_count: caseCount,
    estimated_case_seconds: estimatedCaseSeconds,
    cases_per_session: casesPerSession,
    sessions_required: sessionsRequired,
    chunked: sessionsRequired > 1,
    session_limit_mode: mode,
    hard_session_seconds: hardSessionSeconds || null,
    recurring_free_tier: !!definition?.recurring_free_tier,
    billing_class: String(definition?.billing_class || "unknown"),
    estimated_total_seconds: caseCount * estimatedCaseSeconds + sessionsRequired * sessionStartOverheadSeconds
  };
}

export function rankProviderCandidates(candidates, plan, contracts, options = {}) {
  const budget = contracts?.run_budget || {};
  const maxSessions = Math.max(1, Number(options.maxSessions || budget.max_browser_sessions || 12));
  const estimatedCaseSeconds = Math.max(1, Number(options.estimatedCaseSeconds || budget.estimated_case_seconds || 45));
  const reserveSeconds = Math.max(0, Number(options.reserveSeconds || budget.challenge_expiry_reserve_seconds || 30));
  const sessionStartOverheadSeconds = Math.max(0, Number(options.sessionStartOverheadSeconds || budget.session_start_overhead_seconds || 10));
  const caseCount = Array.isArray(plan?.cases) ? plan.cases.length : 0;

  return candidates.map((candidate, index) => {
    const execution = candidate.definition
      ? providerExecutionPlan(candidate.definition, caseCount, { estimatedCaseSeconds, reserveSeconds, sessionStartOverheadSeconds })
      : null;
    const budgetEligible = !!execution && execution.sessions_required <= maxSessions;
    const recurringPenalty = candidate.definition?.recurring_free_tier ? 0 : 1000;
    const sessionPenalty = execution ? execution.sessions_required * 5 : 5000;
    const basePriority = Number(candidate.definition?.priority || (index + 1) * 100);
    const score = candidate.available && budgetEligible
      ? recurringPenalty + basePriority + sessionPenalty
      : Number.POSITIVE_INFINITY;

    return {
      ...candidate,
      execution,
      budget_eligible: budgetEligible,
      score,
      selection_blocker: !candidate.definition
        ? "provider_unknown"
        : !candidate.available
          ? "credentials_missing"
          : !budgetEligible
            ? "session_budget_exceeded"
            : ""
    };
  }).sort((a, b) => {
    if (a.score !== b.score) return a.score - b.score;
    return Number(a.definition?.priority || 9999) - Number(b.definition?.priority || 9999);
  });
}

export class BrowserRunBudget {
  constructor(contracts) {
    const policy = contracts?.run_budget || {};
    this.maxProviderAttempts = Math.max(1, Number(policy.max_provider_attempts || 4));
    this.maxSessions = Math.max(1, Number(policy.max_browser_sessions || 12));
    this.providerAttempts = 0;
    this.sessionsStarted = 0;
    this.openCircuits = new Set();
  }

  canAttemptProvider(providerId) {
    return this.providerAttempts < this.maxProviderAttempts && !this.openCircuits.has(providerId);
  }

  reserveProvider(providerId) {
    if (!this.canAttemptProvider(providerId)) {
      throw new Error(this.openCircuits.has(providerId)
        ? `browser_provider_circuit_open:${providerId}`
        : "browser_provider_attempt_budget_exceeded");
    }
    this.providerAttempts += 1;
  }

  reserveSession() {
    if (this.sessionsStarted >= this.maxSessions) {
      throw new Error("browser_session_budget_exceeded");
    }
    this.sessionsStarted += 1;
  }

  openCircuit(providerId) {
    this.openCircuits.add(providerId);
  }

  snapshot() {
    return {
      contract: "mad4b.browser-run-budget.v1",
      max_provider_attempts: this.maxProviderAttempts,
      provider_attempts: this.providerAttempts,
      max_browser_sessions: this.maxSessions,
      browser_sessions_started: this.sessionsStarted,
      open_circuits: Array.from(this.openCircuits).sort()
    };
  }
}
