/*
 * ShopSocket on WordSocket's rules engine (0.31): the cart as a live context
 * and the vocabulary's browser halves. Nothing happens without
 * `window.WPS.rules`, so WordSocket 0.30 keeps working.
 */
import type { Viewer } from "shopsocket/storefront/viewer";
import {
  registerActions,
  registerConditions,
  type RulesConfig,
} from "shopsocket/storefront/rules-vocabulary";

export function startRules(viewer: Viewer, config: RulesConfig): void {
  const wps = window.WPS;
  if (!wps?.rules) return;
  const touch = wps.rules.context("cart", () => viewer.cart);
  viewer.onCart(() => touch());
  registerConditions(wps.rules);
  registerActions(
    wps.rules,
    config,
    () => wps.visitorId(),
    () => window.wpSignalConfig?.presence?.kind ?? "",
  );
}
