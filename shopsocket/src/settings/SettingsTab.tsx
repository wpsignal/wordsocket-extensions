/**
 * The ShopSocket tab on WordSocket's settings page: the site-wide switches, the categories they
 * skip and the cart history, read and written as the `shopsocket_storefront` option through
 * `/wp/v2/settings`.
 *
 * - Layout, text, cards, the notice, the spinner and the save button are `@wordpress/ui`.
 * - The form controls come from core's `wp.components` global, which the settings page loads.
 * - A save runs through WordSocket's `useTask`, which shows the toast or the error.
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
};

type ExclusionKey =
  "in_carts_excluded_categories" | "activity_excluded_categories";

function coreComponents(): Components {
  return (window as unknown as { wp: { components: Components } }).wp
    .components;
}

/**
 * The ShopSocket settings tab.
 *
 * @since 0.5.0
 * @since 0.8.0 Laid out with `@wordpress/ui`.
 */
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
                  'Shoppers see "N shoppers have this in their cart right now" under the price. Counts the carts WordSocket\'s History keeps, so it needs that on.',
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
                label={__("Enable", "shopsocket")}
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
