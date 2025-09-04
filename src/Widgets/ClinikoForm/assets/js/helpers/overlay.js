export function showPaymentLoader(formHandlerData) {
  const styles = formHandlerData.appearance?.variables || {};
  const logo = formHandlerData.logo_url;

  jQuery.LoadingOverlay("show", {
    image: "",
    background: "rgba(255, 255, 255, 0.85)",
    zIndex: 9999,
    custom: jQuery(`
      <div style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
        display: flex; flex-direction: column; align-items: center; text-align: center;
        padding: 32px; font-family: ${styles.fontFamily || "sans-serif"}; color: ${styles.colorText || "#333"};">
        ${logo ? `<img src="${logo}" alt="Logo" style="max-height: 60px; margin-bottom: 20px;" class="pulse-logo" />` : ""}
        <div style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">Processing your secure payment...</div>
        <div style="font-size: 14px; color: #666;">Please wait while we confirm your appointment with the clinic.</div>
      </div>
    `),
  });

  if (!document.getElementById("pulse-logo-style")) {
    const style = document.createElement("style");
    style.id = "pulse-logo-style";
    style.innerHTML = `
      @keyframes pulseLogo { 0%{transform:scale(1);opacity:1;} 50%{transform:scale(1.08);opacity:.85;} 100%{transform:scale(1);opacity:1;} }
      .pulse-logo { animation: pulseLogo 1.6s ease-in-out infinite; }
    `;
    document.head.appendChild(style);
  }
}
