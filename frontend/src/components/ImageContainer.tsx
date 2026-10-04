import type { CSSProperties, KeyboardEvent, ReactNode } from "react";

/**
 * Global image container standard: target 480 × 320 (3:2). It shrinks with the viewport
 * (width: 100%, max-width: 480px, aspect-ratio keeps the height proportional) and the image is
 * scaled to fit inside without distortion (object-fit: contain). Use with DetailsWithImage to
 * place it in line with the text on desktop.
 */
export function ImageContainer({
  src,
  alt,
  placeholder = "No image",
  onActivate,
  children,
  style,
}: {
  src: string | null | undefined;
  alt: string;
  placeholder?: ReactNode;
  /** Makes the box clickable (e.g. to choose a new image). */
  onActivate?: () => void;
  /** Overlays (e.g. "Uploading…"). */
  children?: ReactNode;
  style?: CSSProperties;
}) {
  const interactive = Boolean(onActivate);
  return (
    <div
      data-image-container
      role={interactive ? "button" : undefined}
      tabIndex={interactive ? 0 : undefined}
      onClick={onActivate}
      onKeyDown={(e: KeyboardEvent) => {
        if (interactive && (e.key === "Enter" || e.key === " ")) onActivate?.();
      }}
      style={{
        position: "relative",
        width: "100%",
        maxWidth: 480,
        aspectRatio: "3 / 2",
        border: src ? "1px solid #e5e7eb" : "2px dashed #d1d5db",
        borderRadius: 8,
        background: "#f9fafb",
        overflow: "hidden",
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
        cursor: interactive ? "pointer" : "default",
        boxSizing: "border-box",
        ...style,
      }}
    >
      {src ? (
        <img
          src={src}
          alt={alt}
          style={{
            width: "100%",
            height: "100%",
            objectFit: "contain",
            display: "block",
          }}
        />
      ) : (
        <span
          style={{
            color: "#9ca3af",
            fontSize: 13,
            textAlign: "center",
            padding: 12,
          }}
        >
          {placeholder}
        </span>
      )}
      {children}
    </div>
  );
}

/** Details | Image in one row on desktop (top-aligned); the image wraps below the text on narrow screens. */
export function DetailsWithImage({
  details,
  image,
}: {
  details: ReactNode;
  image: ReactNode;
}) {
  return (
    <div
      data-details-with-image
      style={{
        display: "flex",
        flexWrap: "wrap",
        gap: 20,
        alignItems: "flex-start",
      }}
    >
      <div style={{ flex: "1 1 340px", minWidth: 0 }}>{details}</div>
      <div
        style={{ flex: "0 1 480px", minWidth: 0, width: "100%", maxWidth: 480 }}
      >
        {image}
      </div>
    </div>
  );
}
