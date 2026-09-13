import { useEffect, useState } from "@wordpress/element";

/** window.WPS.state, subscribed through onStateChange. */
export function useConnectionState(): WPSConnectionState | null {
  const [state, setState] = useState<WPSConnectionState | null>(window.WPS?.state ?? null);
  useEffect(() => {
    const wps = window.WPS;
    if (!wps) return undefined;
    setState(wps.state);
    return wps.onStateChange(setState);
  }, []);
  return state;
}
