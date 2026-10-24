/**
 * The ShopSocket tab on WordSocket's settings page: the site-wide switches for the shopper-facing
 * features, the categories they skip and the cart history. Reads and writes the
 * `shopsocket_storefront` option through `/wp/v2/settings`.
 *
 * - Layout, text, cards, the notice, the spinner and the save button come from `@wordpress/ui`,
 *   which this repo bundles tree-shaken, the same pieces WordSocket's own tabs are built from.
 * - The form controls (`ToggleControl`, `FormTokenField`, `TextControl`) come from core's
 *   `wp.components` global rather than the `@wordpress/components` this repo bundles for the board
 *   (the board needs a newer version than core ships, at about 2 MB); WordSocket's settings page
 *   loads core's copy, so the tab uses it and stays small.
 * - A save runs through WordSocket's `useTask` (0.29), which shows the "Settings saved." toast or
 *   the red error in WordSocket's own snackbar list.
 */
import { useEffect, useState } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import apiFetch from "@wordpress/api-fetch";
import {
  Button,
  CollapsibleCard,
  Card,
  Notice,
  Spinner,
  Stack,
  Text,
} from "@wordpress/ui";

type Components = typeof import("@wordpress/components");
type Term = { id: number; name: string };

const DEFAULTS: ShopSocketStorefrontSettings = {
  in_carts_enabled: true,
  activity_enabled: true,
  in_carts_excluded_categories: [],
  activity_excluded_categories: [],
  history_enabled: false,
  history_retention_days: 30,
  journeys_retention_months: 12,
};

type ExclusionKey =
  "in_carts_excluded_categories" | "activity_excluded_categories";

function coreComponents(): Components {
  return (window as unknown as { wp: { components: Components } }).wp
    .components;
}

export function SettingsTab() {
  const { ToggleControl, FormTokenField, TextControl } = coreComponents();
  const [settings, setSettings] = useState<ShopSocketStorefrontSettings>(
    window.shopSocketSettings?.storefront ?? DEFAULTS,
  );
  const [terms, setTerms] = useState<Term[] | null>(null);
  const [dirty, setDirty] = useState(false);
  /*
   * Whether the object cache outlives a request, asked twice: the first call plants a marker, and
   * only a persistent cache still holds it for the second. Null until both answers are in.
   */
  const [cache, setCache] = useState<boolean | null>(null);

  useEffect(() => {
    const probe = () =>
      apiFetch<{ persistent: boolean }>({ path: "/shopsocket/v1/cache" });
    probe()
      .then((first) =>
        first.persistent ? true : probe().then((second) => second.persistent),
      )
      .then(setCache)
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    /*
     * Every category, empty ones included (an exclusion may be set up before
     * the products exist), page by page: the REST API caps a page at 100.
     */
    const all: Term[] = [];
    const load = (page: number): Promise<Term[]> =>
      apiFetch<Term[]>({
        path: `/wp/v2/product_cat?per_page=100&page=${page}&hide_empty=false&_fields=id,name`,
      }).then((list) => {
        list.forEach((t) =>
          all.push({ id: Number(t.id), name: String(t.name) }),
        );
        return list.length === 100 ? load(page + 1) : all;
      });
    load(1)
      .then(setTerms)
      .catch(() => setTerms(all));
  }, []);

  const update = (patch: Partial<ShopSocketStorefrontSettings>) => {
    setSettings((current) => ({ ...current, ...patch }));
    setDirty(true);
  };

  const names = terms ?? [];
  const selectedNames = (key: ExclusionKey) =>
    settings[key].map((id) => names.find((t) => t.id === id)?.name ?? `#${id}`);

  const onCategoriesChange =
    (key: ExclusionKey) => (tokens: (string | { value: string })[]) => {
      const ids = tokens
        .map((token) => (typeof token === "string" ? token : token.value))
        .map(
          (name) =>
            names.find((t) => t.name === name)?.id ??
            Number(name.replace(/^#/, "")),
        )
        .filter((id) => Number.isInteger(id) && id > 0);
      update({
        [key]: Array.from(new Set(ids)),
      } as Partial<ShopSocketStorefrontSettings>);
    };

  /** A category exclusion list for one feature; hidden while that feature is off site-wide. */
  const exclusions = (key: ExclusionKey, enabled: boolean, label: string) => {
    if (!enabled) return null;
    if (terms === null) return <Spinner />;
    return (
      <FormTokenField
        label={label}
        value={selectedNames(key)}
        suggestions={names.map((t) => t.name)}
        onChange={onCategoriesChange(key)}
        placeholder={__("Choose or type a category", "shopsocket")}
        __experimentalExpandOnFocus
        help=""
      />
    );
  };

  // The tab is registered only when WordSocket offers useTask (see index.tsx).
  const save = window.wordsocket!.useTask!(
    () =>
      apiFetch<{ shopsocket_storefront: ShopSocketStorefrontSettings }>({
        path: "/wp/v2/settings",
        method: "POST",
        data: { shopsocket_storefront: settings },
      }),
    {
      success: __("Settings saved.", "shopsocket"),
      error: __("Could not save settings.", "shopsocket"),
      onSuccess: (res) => {
        setSettings(res.shopsocket_storefront ?? settings);
        setDirty(false);
      },
    },
  );

  return (
    <Stack direction="column" gap="lg" className="shopsocket-tab">
      <Stack direction="column" gap="sm">
        <Text variant="heading-xl" render={<h2 />}>
          {__("ShopSocket", "shopsocket")}
        </Text>
        <Text variant="body-md" render={<p />}>
          {__(
            "What shoppers see on the storefront. The board under WooCommerce is unaffected by these settings.",
            "shopsocket",
          )}
        </Text>
      </Stack>

      <Text variant="heading-sm" render={<h4 />}>
        {__("Storefront settings", "shopsocket")}
      </Text>
      <Stack direction="row" gap="lg" align="start">
        <CollapsibleCard.Root style={{ flex: 1 }}>
          <CollapsibleCard.Header>
            <Card.Title>{__("In-cart count", "shopsocket")}</Card.Title>
          </CollapsibleCard.Header>
          <CollapsibleCard.Content>
            <Stack direction="column" gap="md" align="stretch">
              <ToggleControl
                label={__("Enable", "shopsocket")}
                help={__(
                  'Shoppers see "N shoppers have this in their cart right now" under the price.',
                  "shopsocket",
                )}
                checked={settings.in_carts_enabled}
                onChange={(value: boolean) =>
                  update({ in_carts_enabled: value })
                }
              />
              {exclusions(
                "in_carts_excluded_categories",
                settings.in_carts_enabled,
                __("Except in these categories", "shopsocket"),
              )}
            </Stack>
          </CollapsibleCard.Content>
        </CollapsibleCard.Root>
        <CollapsibleCard.Root style={{ flex: 1 }}>
          <CollapsibleCard.Header>
            <Card.Title>
              {__("Added-to-cart notification", "shopsocket")}
            </Card.Title>
          </CollapsibleCard.Header>
          <CollapsibleCard.Content>
            <Stack direction="column" gap="md" align="stretch">
              <ToggleControl
                label={__("Show added-to-cart notifications", "shopsocket")}
                help={__(
                  'Shoppers who hold the same product see "Someone just added this to their cart".',
                  "shopsocket",
                )}
                checked={settings.activity_enabled}
                onChange={(value: boolean) =>
                  update({ activity_enabled: value })
                }
              />
              {exclusions(
                "activity_excluded_categories",
                settings.activity_enabled,
                __("Except in these categories", "shopsocket"),
              )}
            </Stack>
          </CollapsibleCard.Content>
        </CollapsibleCard.Root>
      </Stack>
      <Text variant="heading-sm" render={<h4 />}>
        {__("Cart settings", "shopsocket")}
      </Text>
      <CollapsibleCard.Root>
        <CollapsibleCard.Header>
          <Card.Title>{__("History", "shopsocket")}</Card.Title>
        </CollapsibleCard.Header>
        <CollapsibleCard.Content>
          <Stack direction="column" gap="md" align="stretch">
            {cache === false && (
              <Notice.Root intent="warning">
                <Notice.Title>
                  {__("No persistent object cache found", "shopsocket")}
                </Notice.Title>
                <Notice.Description>
                  {__(
                    "With history on, every page a shopper with a cart opens writes to your database, and without a persistent object cache WordPress sends its own lookups there on each of those requests too. A busy store will feel it.",
                    "shopsocket",
                  )}
                </Notice.Description>
                <Notice.Actions>
                  <Notice.ActionLink href="https://developer.wordpress.org/advanced-administration/performance/optimization/">
                    {__("About object caching", "shopsocket")}
                  </Notice.ActionLink>
                </Notice.Actions>
              </Notice.Root>
            )}
            <ToggleControl
              label={__("Keep a history of carts", "shopsocket")}
              help={__(
                "Off: a cart is forgotten with its session, as before. On: each cart stays on your site with what it held, when its shopper left and whether it became an order, and the pages they visited are kept as a journey. Nothing leaves your site.",
                "shopsocket",
              )}
              checked={settings.history_enabled}
              onChange={(value: boolean) => update({ history_enabled: value })}
            />
            {settings.history_enabled && (
              <Stack direction="column" gap="md" align="stretch">
                <TextControl
                  type="number"
                  min={1}
                  max={365}
                  label={__("Keep carts for (days)", "shopsocket")}
                  help={__(
                    "A cart's products, value and outcome, linked to the shopper's account when they were logged in.",
                    "shopsocket",
                  )}
                  value={String(settings.history_retention_days)}
                  onChange={(value: string) =>
                    update({
                      history_retention_days: Math.min(
                        365,
                        Math.max(1, Number(value) || 1),
                      ),
                    })
                  }
                />
                <TextControl
                  type="number"
                  min={1}
                  max={36}
                  label={__("Keep journeys for (months)", "shopsocket")}
                  help={__(
                    "The pages a shopper opened, as ids and durations, under a random browser id and never under an account.",
                    "shopsocket",
                  )}
                  value={String(settings.journeys_retention_months)}
                  onChange={(value: string) =>
                    update({
                      journeys_retention_months: Math.min(
                        36,
                        Math.max(1, Number(value) || 1),
                      ),
                    })
                  }
                />
              </Stack>
            )}
            {window.WPS?.rules && window.shopSocketSettings?.rulesUrl && (
              <Text
                variant="body-sm"
                render={<p className="description shopsocket-tab__note" />}
              >
                {__(
                  "Shopper moments, including the cart reminder ShopSocket ships switched off, live on the ",
                  "shopsocket",
                )}
                <a href={window.shopSocketSettings.rulesUrl}>
                  {__("Moments tab", "shopsocket")}
                </a>
                .
              </Text>
            )}
          </Stack>
        </CollapsibleCard.Content>
      </CollapsibleCard.Root>

      <Stack direction="row">
        <Button
          variant="solid"
          tone="brand"
          onClick={() => save.run()}
          loading={save.isPending}
          disabled={save.isPending || !dirty}
        >
          {__("Save changes", "shopsocket")}
        </Button>
      </Stack>
    </Stack>
  );
}
