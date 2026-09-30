/**
 * The ShopSocket tab on WordSocket's settings page: the site-wide switches
 * for the shopper-facing features and the categories they skip. Reads and
 * writes the `shopsocket_storefront` option through `/wp/v2/settings`.
 *
 * Controls come from core's `wp.components` global rather than the
 * `@wordpress/components` this repo bundles for the board (the board needs a
 * newer version than core ships, at about 2 MB). WordSocket's settings page
 * loads core's copy, so the tab uses it and stays small.
 */
import { useEffect, useState } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import apiFetch from "@wordpress/api-fetch";
import { CollapsibleCard, Card } from "@wordpress/ui";
import { Flex, FlexItem } from "@wordpress/components";

type Components = typeof import("@wordpress/components");
type Term = { id: number; name: string };
type NoticeState = { status: "success" | "error"; text: string } | null;

const DEFAULTS: ShopSocketStorefrontSettings = {
  in_carts_enabled: true,
  activity_enabled: true,
  in_carts_excluded_categories: [],
  activity_excluded_categories: [],
};

type ExclusionKey =
  | "in_carts_excluded_categories"
  | "activity_excluded_categories";

function coreComponents(): Components {
  return (window as unknown as { wp: { components: Components } }).wp
    .components;
}

export function SettingsTab() {
  const { ToggleControl, FormTokenField, Button, Notice, Spinner } =
    coreComponents();
  const [settings, setSettings] = useState<ShopSocketStorefrontSettings>(
    window.shopSocketSettings?.storefront ?? DEFAULTS,
  );
  const [terms, setTerms] = useState<Term[] | null>(null);
  const [saving, setSaving] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [notice, setNotice] = useState<NoticeState>(null);

  useEffect(() => {
    // Every category, empty ones included: an exclusion may be set up before the products exist.
    apiFetch<Term[]>({
      path: "/wp/v2/product_cat?per_page=100&hide_empty=false&_fields=id,name",
    })
      .then((list) =>
        setTerms(list.map((t) => ({ id: Number(t.id), name: String(t.name) }))),
      )
      .catch(() => setTerms([]));
  }, []);

  const update = (patch: Partial<ShopSocketStorefrontSettings>) => {
    setSettings((current) => ({ ...current, ...patch }));
    setDirty(true);
    setNotice(null);
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
        placeholder={__("Type a category name", "shopsocket")}
      />
    );
  };

  const save = () => {
    setSaving(true);
    apiFetch<{ shopsocket_storefront: ShopSocketStorefrontSettings }>({
      path: "/wp/v2/settings",
      method: "POST",
      data: { shopsocket_storefront: settings },
    })
      .then((res) => {
        setSettings(res.shopsocket_storefront ?? settings);
        setDirty(false);
        setNotice({
          status: "success",
          text: __("Settings saved.", "shopsocket"),
        });
      })
      .catch((err: { message?: string }) => {
        setNotice({
          status: "error",
          text: err?.message || __("Could not save settings.", "shopsocket"),
        });
      })
      .finally(() => setSaving(false));
  };

  return (
    <div className="shopsocket-tab">
      <h2>{__("ShopSocket", "shopsocket")}</h2>
      <p>
        {__(
          "What shoppers see on the storefront. The board under WooCommerce is unaffected by these settings.",
          "shopsocket",
        )}
      </p>

      <h4>{__("Storefront settings", "shopsocket")}</h4>

      {notice && (
        <Notice
          status={notice.status}
          isDismissible
          onRemove={() => setNotice(null)}
        >
          {notice.text}
        </Notice>
      )}

      <Flex direction="column" gap={4}>
        <FlexItem>
          <CollapsibleCard.Root defaultOpen>
            <CollapsibleCard.Header>
              <Card.Title>{__("In-cart count", "shopsocket")}</Card.Title>
            </CollapsibleCard.Header>
            <CollapsibleCard.Content>
              <Flex direction="column" gap={4} align="stretch">
                <FlexItem>
                  <ToggleControl
                    label={
                      settings.in_carts_enabled
                        ? __("Disable", "shopsocket")
                        : __("Enable", "shopsocket")
                    }
                    help={
                      settings.in_carts_enabled
                        ? __(
                            'Click to disable "N shoppers have this in their cart right now" feature site-wide.',
                            "shopsocket",
                          )
                        : __(
                            'Click to enable "N shoppers have this in their cart right now" feature site-wide.',
                            "shopsocket",
                          )
                    }
                    checked={settings.in_carts_enabled}
                    onChange={(value: boolean) =>
                      update({ in_carts_enabled: value })
                    }
                  />
                </FlexItem>
                <FlexItem>
                  {exclusions(
                    "in_carts_excluded_categories",
                    settings.in_carts_enabled,
                    __("Disable the count in these categories", "shopsocket"),
                  )}
                </FlexItem>
              </Flex>
            </CollapsibleCard.Content>
          </CollapsibleCard.Root>
        </FlexItem>
        <FlexItem>
          <CollapsibleCard.Root defaultOpen>
            <CollapsibleCard.Header>
              <Card.Title>
                {__("Added-to-cart notification", "shopsocket")}
              </Card.Title>
            </CollapsibleCard.Header>
            <CollapsibleCard.Content>
              <Flex direction="column" gap={4} align="stretch">
                <FlexItem>
                  <ToggleControl
                    label={settings.activity_enabled ? __("Disable", "shopsocket") : __("Enable", "shopsocket")}
                    help={settings.activity_enabled ? __(
                        'Click to disable "Someone just added this to their cart" feature site-wide. Shown to shoppers who hold the same product in their cart.',
                        "shopsocket",
                      ) : __(
                        'Click to enable "Someone just added this to their cart" feature site-wide. Shown to shoppers who hold the same product in their cart.',
                        "shopsocket",
                      )
                    }
                    checked={settings.activity_enabled}
                    onChange={(value: boolean) =>
                      update({ activity_enabled: value })
                    }
                  />
                </FlexItem>
                <FlexItem>
                  {exclusions(
                    "activity_excluded_categories",
                    settings.activity_enabled,
                    __("Disable the notification in these categories", "shopsocket"),
                  )}
                </FlexItem>
              </Flex>
            </CollapsibleCard.Content>
          </CollapsibleCard.Root>
        </FlexItem>
        <FlexItem>
          <p className="description shopsocket-tab__note">
            {__(
              "A product set to On or Off on its own edit screen (Product data, ShopSocket tab) keeps that choice whatever these settings say.",
              "shopsocket",
            )}
          </p>

          <Button
            variant="primary"
            onClick={save}
            isBusy={saving}
            disabled={saving || !dirty}
          >
            {__("Save changes", "shopsocket")}
          </Button>
        </FlexItem>
      </Flex>
    </div>
  );
}
