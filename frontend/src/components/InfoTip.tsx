import { useId, useState, type ReactNode } from "react";

/**
 * A small "i" button that explains a field. The text shows on hover and on keyboard focus (and
 * toggles on click / tap), is linked with aria-describedby, and Escape hides it.
 */
export function InfoTip({
  label,
  children,
}: {
  label: string;
  children: ReactNode;
}) {
  const id = useId();
  const [open, setOpen] = useState(false);

  return (
    <span
      style={{
        position: "relative",
        display: "inline-flex",
        verticalAlign: "middle",
      }}
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => setOpen(false)}
    >
      <button
        type="button"
        aria-label={`About ${label}`}
        aria-describedby={open ? id : undefined}
        aria-expanded={open}
        data-info-tip={label}
        onFocus={() => setOpen(true)}
        onBlur={() => setOpen(false)}
        onClick={() => setOpen((v) => !v)}
        onKeyDown={(e) => {
          if (e.key === "Escape") setOpen(false);
        }}
        style={{
          width: 16,
          height: 16,
          marginLeft: 4,
          padding: 0,
          border: "1px solid #9ca3af",
          borderRadius: "50%",
          background: "#fff",
          color: "#4b5563",
          fontSize: 10,
          fontWeight: 700,
          lineHeight: "14px",
          cursor: "help",
        }}
      >
        i
      </button>
      {open && (
        <span
          role="tooltip"
          id={id}
          style={{
            position: "absolute",
            top: "100%",
            left: 0,
            zIndex: 30,
            marginTop: 4,
            width: "max-content",
            maxWidth: "min(300px, 80vw)",
            padding: "8px 10px",
            borderRadius: 6,
            background: "#111827",
            color: "#f9fafb",
            fontSize: 12,
            fontWeight: 400,
            lineHeight: 1.45,
            whiteSpace: "normal",
            textAlign: "left",
            boxShadow: "0 4px 12px rgba(0,0,0,0.18)",
          }}
        >
          {children}
        </span>
      )}
    </span>
  );
}
