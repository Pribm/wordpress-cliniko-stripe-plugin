export function showToast(message, type = "error") {
  const isSuccess = type === "success";

  const icon = isSuccess
    ? `<svg xmlns="http://www.w3.org/2000/svg" height="24" width="24" fill="#2e7d32" viewBox="0 0 24 24"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>`
    : `<svg xmlns="http://www.w3.org/2000/svg" height="24" width="24" fill="#f44336" viewBox="0 0 24 24"><path d="M1 21h22L12 2 1 21zm13-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>`;

  const bgColor = isSuccess ? "#f1f8f4" : "#fff";
  const borderColor = isSuccess ? "#c8e6c9" : "#eee";
  const textColor = isSuccess ? "#2e7d32" : "#333";

  Toastify({
    text: `
      <div style="display: flex; align-items: center; gap: 12px;">
        ${icon}
        <span style="color: ${textColor}; font-size: 14px; font-weight: 500;">
          ${message}
        </span>
      </div>
    `,
    duration: 4000,
    gravity: "bottom",
    position: "left",
    stopOnFocus: true,
    escapeMarkup: false,
    style: {
      background: bgColor,
      border: `1px solid ${borderColor}`,
      borderRadius: "8px",
      padding: "12px 16px",
      minWidth: "260px",
      maxWidth: "360px",
      boxShadow: "0 2px 8px rgba(0,0,0,0.08)",
    },
  }).showToast();
}
