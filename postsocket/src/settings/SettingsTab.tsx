/**
 * The PostSocket tab: which post types update live, how the feed reads, the live blog URL base.
 *
 * @since 0.1.0
 */
import apiFetch from "@wordpress/api-fetch";
import {
  Button,
  Card,
  CardBody,
  CardHeader,
  CheckboxControl,
  Notice,
  RangeControl,
  Spinner,
  TextControl,
  ToggleControl,
  __experimentalVStack as VStack,
} from "@wordpress/components";
import { useEffect, useState } from "@wordpress/element";
import { __ } from "@wordpress/i18n";

interface Settings {
  living_types: string[];
  entries_per_page: number;
  show_author: boolean;
  show_times: boolean;
  rewrite_slug: string;
}

interface SettingsResponse {
  settings: Settings;
  types: Array<{ name: string; label: string }>;
  bounds: { entries_min: number; entries_max: number };
}

export function SettingsTab(): JSX.Element | null {
  const api = window.wordsocket;
  const config = window.postsocketSettings;
  const [data, setData] = useState<SettingsResponse | null>(null);
  const [draft, setDraft] = useState<Settings | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);

  useEffect(() => {
    if (!config) return;
    apiFetch<SettingsResponse>({ url: config.settingsUrl })
      .then((response) => {
        setData(response);
        setDraft(response.settings);
      })
      .catch((err: { message?: string }) =>
        setLoadError(
          err?.message ?? __("Could not load the settings.", "postsocket"),
        ),
      );
  }, []);

  if (!api?.useTask || !config) return null;
  const save = api.useTask(
    async (next: Settings) =>
      apiFetch<SettingsResponse>({
        url: config.settingsUrl,
        method: "POST",
        data: next,
      }),
    {
      success: __("PostSocket settings saved.", "postsocket"),
      error: __("The settings could not be saved.", "postsocket"),
      onSuccess: (response) => {
        setData(response);
        setDraft(response.settings);
      },
    },
  );

  if (loadError)
    return (
      <Notice status="error" isDismissible={false}>
        {loadError}
      </Notice>
    );
  if (!data || !draft) return <Spinner />;

  const dirty = JSON.stringify(draft) !== JSON.stringify(data.settings);
  const toggleType = (name: string, on: boolean): void =>
    setDraft({
      ...draft,
      living_types: on
        ? [...draft.living_types, name]
        : draft.living_types.filter((t) => t !== name),
    });

  return (
    <VStack spacing={4} className="postsocket-settings">
      {!config.wordsocketOk && (
        <Notice status="warning" isDismissible={false}>
          {__(
            "WordSocket needs an update before readers see changes live.",
            "postsocket",
          )}
        </Notice>
      )}

      <Card>
        <CardHeader>{__("Living articles", "postsocket")}</CardHeader>
        <CardBody>
          <VStack spacing={3}>
            <p>
              {__(
                "Readers of these post types see your saved edits land on the page without a reload. Live blogs are always live.",
                "postsocket",
              )}
            </p>
            {data.types.map((type) => (
              <CheckboxControl
                __nextHasNoMarginBottom
                key={type.name}
                label={type.label}
                checked={draft.living_types.includes(type.name)}
                onChange={(on) => toggleType(type.name, on)}
              />
            ))}
          </VStack>
        </CardBody>
      </Card>

      <Card>
        <CardHeader>{__("Feed", "postsocket")}</CardHeader>
        <CardBody>
          <VStack spacing={4}>
            <RangeControl
              __nextHasNoMarginBottom
              label={__("Entries shown at first", "postsocket")}
              help={__("Older entries load on request.", "postsocket")}
              min={data.bounds.entries_min}
              max={data.bounds.entries_max}
              value={draft.entries_per_page}
              onChange={(value) =>
                setDraft({
                  ...draft,
                  entries_per_page: Number(value ?? draft.entries_per_page),
                })
              }
            />
            <ToggleControl
              __nextHasNoMarginBottom
              label={__("Show who wrote each entry", "postsocket")}
              checked={draft.show_author}
              onChange={(on) => setDraft({ ...draft, show_author: on })}
            />
            <ToggleControl
              __nextHasNoMarginBottom
              label={__("Show the time of each entry", "postsocket")}
              checked={draft.show_times}
              onChange={(on) => setDraft({ ...draft, show_times: on })}
            />
          </VStack>
        </CardBody>
      </Card>

      <Card>
        <CardHeader>{__("URLs", "postsocket")}</CardHeader>
        <CardBody>
          <TextControl
            __nextHasNoMarginBottom
            label={__("Live blog URL base", "postsocket")}
            help={__(
              "Live blogs live under this path, for example /live/match-day/.",
              "postsocket",
            )}
            value={draft.rewrite_slug}
            onChange={(value) => setDraft({ ...draft, rewrite_slug: value })}
          />
        </CardBody>
      </Card>

      <div>
        <Button
          variant="primary"
          disabled={!dirty || save.isPending}
          isBusy={save.isPending}
          onClick={() => void save.run(draft)}
        >
          {__("Save changes", "postsocket")}
        </Button>
      </div>
    </VStack>
  );
}
