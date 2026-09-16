/** The one hook the Live Stock block's editor side uses; the package ships no types. */
declare module "@wordpress/block-editor" {
	export function useBlockProps( props?: Record< string, unknown > ): Record< string, unknown >;
}
