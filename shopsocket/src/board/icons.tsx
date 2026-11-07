/*
 * The board's own icons, drawn like those of `@wordpress/icons`, which has none for sound.
 */

/**
 * A speaker with sound coming from it.
 *
 * @since 0.8.0
 */
export const soundOn = (
  <svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.5"
  >
    <path d="M4.75 9.75h3l4-3.5v11.5l-4-3.5h-3z" />
    <path d="M15 9.5a3.5 3.5 0 0 1 0 5" />
    <path d="M17.25 7.25a6.75 6.75 0 0 1 0 9.5" />
  </svg>
);

/**
 * A speaker crossed out.
 *
 * @since 0.8.0
 */
export const soundOff = (
  <svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.5"
  >
    <path d="M4.75 9.75h3l4-3.5v11.5l-4-3.5h-3z" />
    <path d="m15.25 9.75 4.5 4.5m0-4.5-4.5 4.5" />
  </svg>
);
