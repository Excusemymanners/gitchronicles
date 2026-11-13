/*
 * Icons of the mini player, drawn with strokes like the workspace icons. They are decorative: the name of each
 * button is its aria-label.
 */

type Props = {
    className?: string;
};

const strokeProps = {
    'aria-hidden': true,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.75,
    strokeLinecap: 'round',
    strokeLinejoin: 'round',
} as const;

/** Two bars: pauses the video. */
export function PauseIcon({ className = 'size-4' }: Props) {
    return (
        <svg aria-hidden="true" viewBox="0 0 24 24" fill="currentColor" className={className}>
            <rect x="6" y="5" width="4" height="14" rx="1" />
            <rect x="14" y="5" width="4" height="14" rx="1" />
        </svg>
    );
}

/** A speaker with sound waves: the sound is on. */
export function VolumeOnIcon({ className = 'size-4' }: Props) {
    return (
        <svg {...strokeProps} className={className}>
            <path d="M4 9.5v5h3.5L12 19V5L7.5 9.5H4ZM15.5 9a4 4 0 0 1 0 6M18 6.5a8 8 0 0 1 0 11" />
        </svg>
    );
}

/** A speaker with a cross: the sound is off. */
export function VolumeOffIcon({ className = 'size-4' }: Props) {
    return (
        <svg {...strokeProps} className={className}>
            <path d="M4 9.5v5h3.5L12 19V5L7.5 9.5H4ZM16 9.5l5 5M21 9.5l-5 5" />
        </svg>
    );
}

/** A small window inside a larger one: Picture-in-Picture. */
export function PictureInPictureIcon({ className = 'size-4' }: Props) {
    return (
        <svg {...strokeProps} className={className}>
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <rect x="12" y="12" width="7" height="5" rx="1" fill="currentColor" stroke="none" />
        </svg>
    );
}

/** A square with an arrow leaving it: opens the page of the video. */
export function OpenPageIcon({ className = 'size-4' }: Props) {
    return (
        <svg {...strokeProps} className={className}>
            <path d="M14 4h6v6M20 4l-9 9M18 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4" />
        </svg>
    );
}
