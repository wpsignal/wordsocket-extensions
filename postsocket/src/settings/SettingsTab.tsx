/**
 * The PostSocket tab: which post types update live, and how the feed reads.
 *
 * @since 0.1.0
 */
import apiFetch from "@wordpress/api-fetch";
import { useEffect, useState } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import { Button, ButtonLink, Card, CheckboxControl, InputControl, Notice, Spinner, Stack, SwitchControl, Text } from "@wordpress/ui";

interface Settings {
  living_types: string[];
  entries_per_page: number;
  show_author: boolean;
  show_times: boolean;
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
      <Notice.Root intent="error">
        <Notice.Description>{loadError}</Notice.Description>
      </Notice.Root>
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
    <Stack direction="column" gap="lg" className="postsocket-settings">
      {!config.wordsocketOk && (
        <Notice.Root intent="warning">
          <Notice.Description>{__("WordSocket needs an update before readers see changes live.", "postsocket")}</Notice.Description>
        </Notice.Root>
      )}

      <Card.Root>
        <Card.Header>
          <Card.Title>{__("Live blogs", "postsocket")}</Card.Title>
        </Card.Header>
        <Card.Content>
          <Stack direction="column" gap="md" align="start">
            <Text variant="body-md">
              {__("Create live blogs and run their entries on the Live Desk, then place a live blog on any post with the Live Feed block.", "postsocket")}
            </Text>
            <ButtonLink variant="outline" href={config.liveblogsUrl}>
              {__("Open the Live Desk", "postsocket")}
            </ButtonLink>
          </Stack>
        </Card.Content>
      </Card.Root>

      <Card.Root>
        <Card.Header>
          <Card.Title>{__("Living articles", "postsocket")}</Card.Title>
        </Card.Header>
        <Card.Content>
          <Stack direction="column" gap="md">
            <Text variant="body-md">
              {__(
                "Posts of these types can update live: switch Live editing on in a post's editor, and readers see your saved edits land on the page without a reload. A post showing a live blog is always live, and posts built with Elementor or Divi are left alone.",
                "postsocket",
              )}
            </Text>
            {data.types.map((type) => (
              <CheckboxControl
                key={type.name}
                label={type.label}
                checked={draft.living_types.includes(type.name)}
                onCheckedChange={(on) => toggleType(type.name, on)}
              />
            ))}
          </Stack>
        </Card.Content>
      </Card.Root>

      <Card.Root>
        <Card.Header>
          <Card.Title>{__("Feed", "postsocket")}</Card.Title>
        </Card.Header>
        <Card.Content>
          <Stack direction="column" gap="md">
            <InputControl
              type="number"
              label={__("Entries shown at first", "postsocket")}
              description={__("Older entries load on request.", "postsocket")}
              min={data.bounds.entries_min}
              max={data.bounds.entries_max}
              value={String(draft.entries_per_page)}
              onValueChange={(value) => setDraft({ ...draft, entries_per_page: Number(value) || draft.entries_per_page })}
            />
            <SwitchControl
              label={__("Show who wrote each entry", "postsocket")}
              checked={draft.show_author}
              onCheckedChange={(on) => setDraft({ ...draft, show_author: on })}
            />
            <SwitchControl
              label={__("Show the time of each entry", "postsocket")}
              checked={draft.show_times}
              onCheckedChange={(on) => setDraft({ ...draft, show_times: on })}
            />
          </Stack>
        </Card.Content>
      </Card.Root>

      <div>
        <Button disabled={!dirty} loading={save.isPending} onClick={() => void save.run(draft)}>
          {__("Save changes", "postsocket")}
        </Button>
      </div>
    </Stack>
  );
}
