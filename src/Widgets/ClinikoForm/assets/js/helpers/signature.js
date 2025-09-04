export function setupSignatureCanvas() {
  const canvas = document.getElementById("signature-pad");
  const clearBtn = document.getElementById("clear-signature");
  const signatureDataInput = document.getElementById("signature-data");

  if (!canvas || !clearBtn || !signatureDataInput) return;

  const ctx = canvas.getContext("2d");
  let drawing = false;
  ctx.strokeStyle = "#000";
  ctx.lineWidth = 2;
  ctx.lineCap = "round";

  function saveSignature() {
    signatureDataInput.value = canvas.toDataURL("image/png");
  }

  canvas.addEventListener("mousedown", e => {
    drawing = true;
    ctx.beginPath();
    ctx.moveTo(e.offsetX, e.offsetY);
  });

  canvas.addEventListener("mousemove", e => {
    if (!drawing) return;
    ctx.lineTo(e.offsetX, e.offsetY);
    ctx.stroke();
  });

  canvas.addEventListener("mouseup", () => { drawing = false; saveSignature(); });
  canvas.addEventListener("mouseleave", () => { if (drawing) { drawing = false; saveSignature(); }});

  // Touch
  canvas.addEventListener("touchstart", e => {
    e.preventDefault();
    drawing = true;
    const t = e.touches[0];
    ctx.beginPath();
    ctx.moveTo(t.clientX - canvas.offsetLeft, t.clientY - canvas.offsetTop);
  });

  canvas.addEventListener("touchmove", e => {
    e.preventDefault();
    if (!drawing) return;
    const t = e.touches[0];
    ctx.lineTo(t.clientX - canvas.offsetLeft, t.clientY - canvas.offsetTop);
    ctx.stroke();
  });

  canvas.addEventListener("touchend", () => { drawing = false; saveSignature(); });

  clearBtn.addEventListener("click", () => {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    signatureDataInput.value = "";
  });
}
