/** Usernames excluded from automatic handoff and manual take (test accounts). */
export function getHandoffExcludedUsernames(): Set<string> {
  const raw = process.env['HANDOFF_EXCLUDED_AGENT_USERNAMES'] ?? 'zero.dev';
  return new Set(
    raw.split(',').map((s) => s.trim().toLowerCase()).filter(Boolean),
  );
}

export function isHandoffExcludedAgent(username: string | null | undefined): boolean {
  if (!username) return false;
  return getHandoffExcludedUsernames().has(username.toLowerCase());
}
