/**
 * The Live Desk: the list of live blogs, or one live blog's desk when the URL names it.
 *
 * @since 0.1.0
 */
import "@wordpress/theme/design-tokens.css";
import "@wordpress/components/build-style/style.css";
import "@wordpress/dataviews/build-style/style.css";
import "./desk.css";
import { createRoot, useCallback, useEffect, useState } from "@wordpress/element";
import { DeskView } from "./DeskView";
import { LiveblogsView } from "./LiveblogsView";

const cfg = window.postsocketDesk;

/** The live blog the URL names, or 0. */
function fromUrl(): number {
  return Number(new URL(window.location.href).searchParams.get("liveblog") ?? 0) || 0;
}

function Desk(): JSX.Element {
  const [liveblog, setLiveblog] = useState(fromUrl);

  useEffect(() => {
    const onPop = (): void => setLiveblog(fromUrl());
    window.addEventListener("popstate", onPop);
    return () => window.removeEventListener("popstate", onPop);
  }, []);

  const go = useCallback((id: number) => {
    const url = new URL(window.location.href);
    if (id > 0) url.searchParams.set("liveblog", String(id));
    else url.searchParams.delete("liveblog");
    window.history.pushState(null, "", url);
    setLiveblog(id);
    window.scrollTo(0, 0);
  }, []);

  return liveblog > 0 ? <DeskView key={liveblog} id={liveblog} back={() => go(0)} /> : <LiveblogsView open={go} />;
}

const root = document.getElementById("postsocket-desk");
if (root && cfg) createRoot(root).render(<Desk />);
