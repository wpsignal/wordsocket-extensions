/**
 * A dialog that asks for a live blog's name, for creating one or renaming it.
 *
 * @since 0.1.0
 */
import { useState } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import { Button, Dialog, InputControl, Notice, Stack } from "@wordpress/ui";

interface Props {
  title: string;
  action: string;
  initial?: string;
  onSubmit: (name: string) => Promise<void>;
  onClose: () => void;
}

/**
 * Ask for a name and hand it to `onSubmit`; the dialog stays open with the error when that fails.
 *
 * @since 0.1.0
 */
export function NameDialog({ title, action, initial = "", onSubmit, onClose }: Props): JSX.Element {
  const [name, setName] = useState(initial);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (): Promise<void> => {
    if (!name.trim()) return;
    setBusy(true);
    setError(null);
    try {
      await onSubmit(name.trim());
    } catch (err) {
      setError((err as { message?: string })?.message ?? __("That did not work.", "postsocket"));
      setBusy(false);
    }
  };

  return (
    <Dialog.Root
      open
      onOpenChange={(open: boolean) => {
        if (!open) onClose();
      }}
    >
      <Dialog.Popup size="small">
        <Dialog.Header>
          <Dialog.Title>{title}</Dialog.Title>
          <Dialog.CloseIconButton />
        </Dialog.Header>
        <Dialog.Content>
          <form
            id="postsocket-name-form"
            onSubmit={(event) => {
              event.preventDefault();
              void submit();
            }}
          >
            <Stack direction="column" gap="md">
              {error && (
                <Notice.Root intent="error">
                  <Notice.Description>{error}</Notice.Description>
                </Notice.Root>
              )}
              <InputControl
                label={__("Name", "postsocket")}
                description={__("Editors see it on the desk and when choosing a live blog; readers see the post it is placed in.", "postsocket")}
                value={name}
                onValueChange={setName}
                autoFocus
              />
            </Stack>
          </form>
        </Dialog.Content>
        <Dialog.Footer>
          <Dialog.Action variant="outline">{__("Cancel", "postsocket")}</Dialog.Action>
          <Button type="submit" form="postsocket-name-form" loading={busy} disabled={!name.trim()}>
            {action}
          </Button>
        </Dialog.Footer>
      </Dialog.Popup>
    </Dialog.Root>
  );
}
