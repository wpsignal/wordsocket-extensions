/*
 * The journey string a shopper's presence carries (`kind:id:s` steps joined by `>`), read into the
 * pages the board shows. The string is the browser's word, so every step is checked.
 */

/**
 * A page of a route.
 *
 * @since 0.8.0
 */
export interface RouteStep {
  kind: string;
  id: number;
}

/** Whether a kind is a page kind and not a name inherited from Object. */
const KIND = /^[a-z_]+$/;

/**
 * The pages of a journey as announced, hidden spells dropped and a page repeated in a row kept
 * once, the newest `limit` of them.
 *
 * @since 0.8.0
 */
export function routeSteps(
  j: string,
  limit: number = Number.POSITIVE_INFINITY,
): RouteStep[] {
  const steps: RouteStep[] = [];
  j.split(">").forEach((raw) => {
    const [kind, id] = raw.split(":");
    if (!KIND.test(kind ?? "") || kind === "away") return;
    const step = { kind, id: Number(id) || 0 };
    const last = steps[steps.length - 1];
    if (last && last.kind === step.kind && last.id === step.id) return;
    steps.push(step);
  });
  return steps.slice(-limit);
}

/**
 * The page a shopper is on: the journey's last page, the hidden spell after it skipped; `other:0`
 * for a journey that has none.
 *
 * @since 0.8.0
 */
export function openStep(j: string): RouteStep {
  const steps = routeSteps(j);
  return steps[steps.length - 1] ?? { kind: "other", id: 0 };
}
