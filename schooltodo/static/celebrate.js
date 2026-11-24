const CONFETTI_COLORS = ["#0075f8", "#1d7044", "#f04438", "#6b3fd4", "#ffb800", "#00b8d9", "#ff6fa8"];

function isComplete(counts) {
  const p = progressOf(counts);
  return p.target > 0 && p.done === p.target;
}

let celebrateT;
function celebrate(title) {
  const box = document.getElementById("celebrate");
  document.getElementById("celebrateSheet").textContent = title;
  box.classList.add("show");
  clearTimeout(celebrateT);
  celebrateT = setTimeout(closeCelebrate, 5000);
  if (!matchMedia("(prefers-reduced-motion: reduce)").matches) throwConfetti();
}

function closeCelebrate() {
  clearTimeout(celebrateT);
  document.getElementById("celebrate").classList.remove("show");
}

function throwConfetti() {
  const canvas = document.createElement("canvas");
  canvas.className = "confetti";
  document.body.appendChild(canvas);
  const ctx = canvas.getContext("2d");
  const dpr = window.devicePixelRatio || 1;
  const w = window.innerWidth;
  const h = window.innerHeight;
  canvas.width = w * dpr;
  canvas.height = h * dpr;
  ctx.scale(dpr, dpr);

  const pieces = [];
  for (let i = 0; i < 180; i++) {
    const left = i % 2 === 0;
    const angle = (left ? -60 : -120) + (Math.random() - 0.5) * 40;
    const speed = 11 + Math.random() * 9;
    pieces.push({
      x: left ? 0 : w,
      y: h,
      vx: Math.cos(angle * Math.PI / 180) * speed,
      vy: Math.sin(angle * Math.PI / 180) * speed,
      size: 6 + Math.random() * 6,
      spin: Math.random() * Math.PI,
      vspin: (Math.random() - 0.5) * 0.3,
      color: CONFETTI_COLORS[i % CONFETTI_COLORS.length],
    });
  }

  const start = performance.now();
  const frame = (now) => {
    const t = now - start;
    ctx.clearRect(0, 0, w, h);
    for (const p of pieces) {
      p.vy += 0.25;
      p.vx *= 0.99;
      p.x += p.vx;
      p.y += p.vy;
      p.spin += p.vspin;
      ctx.save();
      ctx.translate(p.x, p.y);
      ctx.rotate(p.spin);
      ctx.globalAlpha = Math.max(0, 1 - Math.max(0, t - 2800) / 1200);
      ctx.fillStyle = p.color;
      ctx.fillRect(-p.size / 2, -p.size / 4, p.size, p.size / 2);
      ctx.restore();
    }
    if (t < 4000) requestAnimationFrame(frame);
    else canvas.remove();
  };
  requestAnimationFrame(frame);
}
