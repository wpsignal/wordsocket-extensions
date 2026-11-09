/**
 * The editor packages the panels and the block use, as core's `wp.*` globals; the packages are not installed, so the shapes are declared here.
 *
 * @since 0.1.0
 */
declare module "@wordpress/editor" {
  export const PluginDocumentSettingPanel: React.ComponentType<{
    name: string;
    title: string;
    className?: string;
    icon?: string | React.ReactNode;
    children?: React.ReactNode;
  }>;
}

declare module "@wordpress/plugins" {
  export function registerPlugin(name: string, settings: { render: React.ComponentType; icon?: string | React.ReactNode; scope?: string }): void;
}

declare module "@wordpress/core-data" {
  export function useEntityProp<T = unknown>(kind: string, name: string, prop: string, id?: number): [T, (value: T) => void, T];
  export function useEntityRecords<T = Record<string, unknown>>(
    kind: string,
    name: string,
    query?: Record<string, unknown>,
    options?: { enabled?: boolean },
  ): { records: T[] | null; isResolving: boolean; hasResolved: boolean; totalItems: number | null };
}

declare module "@wordpress/data" {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  export function useSelect<T>(mapSelect: (select: (store: string) => any) => T, deps?: unknown[]): T;
}

declare module "@wordpress/blocks" {
  export function registerBlockType(name: string, settings: Record<string, unknown>): void;
}

declare module "@wordpress/block-editor" {
  export function useBlockProps(props?: Record<string, unknown>): Record<string, unknown>;
}
