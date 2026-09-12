/* Sistem Kuliah — dashboard (wire ke API Laravel, session cookie + CSRF) */
(() => {
  "use strict";

  /* ============ Helpers ============ */
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s ?? "").replace(/[&<>"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
  const fmt = (t) => (t || "").replace(":", "."); // "09:20" → "09.20"

  function xsrfToken() {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return m ? decodeURIComponent(m[1]) : "";
  }

  async function api(path, { method = "GET", body } = {}) {
    const res = await fetch(path, {
      method,
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        ...(body ? { "Content-Type": "application/json" } : {}),
        ...(method !== "GET" ? { "X-XSRF-TOKEN": xsrfToken() } : {}),
      },
      ...(body ? { body: JSON.stringify(body) } : {}),
    });
    if (res.status === 401 && !path.startsWith("/api/auth/")) {
      location.replace("/app/index.html");
      throw new Error("unauthorized");
    }
    const data = await res.json().catch(() => ({}));
    return { ok: res.ok, status: res.status, data };
  }

  const firstError = (data) => {
    if (data && data.errors) {
      const v = Object.values(data.errors)[0];
      if (Array.isArray(v)) return v[0];
      if (typeof v === "string") return v;
    }
    return (data && data.message) || "Terjadi kesalahan. Coba lagi.";
  };

  const initialsOf = (n) => String(n || "?").split(" ").map((w) => w[0]).slice(0, 2).join("").toUpperCase();

  /* ============ Preferensi (localStorage) ============ */
  const savedTheme = localStorage.getItem("sk-theme");
  if (savedTheme === "light" || savedTheme === "dark") document.documentElement.dataset.theme = savedTheme;
  if (localStorage.getItem("sk-anim") === "off") document.documentElement.classList.add("no-anim");
  const vib = (p) => { if (localStorage.getItem("sk-vib") !== "off" && navigator.vibrate) navigator.vibrate(p); };

  /* ============ State ============ */
  const state = {
    user: null,
    dashboard: null,
    week: null, // {weekly, makeup, all, current_day, week_start, week_end}
    tasks: [], // semua tugas (dari /api/tasks)
    manageable_subjects: [], // semua matkul (untuk tambah tugas PJ/Admin)
    admin: null,
    todayKey: new Date().toISOString().slice(0, 10),
  };

  // Demo hook: ?t=09:45 memaksa jam tertentu; ?d=4 memaksa hari (0 Minggu..6 Sabtu).
  const demoT = new URLSearchParams(location.search).get("t");
  const demoD = new URLSearchParams(location.search).get("d");
  const nowMin = () => { const d = new Date(); return d.getHours() * 60 + d.getMinutes() + d.getSeconds() / 60; };
  const t = () => (demoT ? +demoT.split(":")[0] * 60 + +demoT.split(":")[1] : nowMin());

  // index hari chip: API pakai ISO 1..6 (Senin..Sabtu); chip urut Senin→Minggu
  const DAY_ORDER = [1, 2, 3, 4, 5, 6];
  const DAY_ABBR = { 1: "Sen", 2: "Sel", 3: "Rab", 4: "Kam", 5: "Jum", 6: "Sab" };
  const GRACE = 2; // menit tenggang setelah kelas selesai di beranda

  const jdDay0 = demoD !== null ? +demoD % 7 : new Date().getDay(); // 0 Minggu..6 Sabtu
  const jdDayIso = jdDay0 === 0 ? 7 : jdDay0; // ISO: 7 = Minggu

  // Senin minggu ini (ISO) → tanggal chip
  function dateKeyOf(isoDay) {
    const mon = new Date();
    mon.setDate(mon.getDate() - ((mon.getDay() + 6) % 7));
    mon.setDate(mon.getDate() + (isoDay - 1));
    return mon.toISOString().slice(0, 10);
  }
  const todayIso = (() => { const d = new Date().getDay(); return d === 0 ? 7 : d; })();

  const toMin = (t5) => { const [h, m] = t5.split(":").map(Number); return h * 60 + m; };

  // Klasifikasi kartu jadwal
  function cardStatus(c, n) {
    if (c.status === "CANCELLED") return "off";
    const s = toMin(c.start_time), e = toMin(c.end_time);
    if (n >= e) return "done";
    if (n >= s) return "live";
    return "next";
  }
  const isOnline = (c) => c.status === "ONLINE" && c.meeting_url;

  /* ============ Toast ============ */
  let toastTimer;
  function showToast(msg) {
    const el = $("toast");
    el.textContent = msg;
    el.classList.add("show");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove("show"), 2200);
  }

  /* ============ Render: Beranda ============ */
  function renderGreeting() {
    const h = Math.floor(t() / 60);
    const word = h < 11 ? "pagi" : h < 15 ? "siang" : h < 19 ? "sore" : "malam";
    const first = (state.user?.name || "").split(" ")[0] || "";
    $("greet").innerHTML = "Selamat " + word + ", <em>" + esc(first) + "</em>";
    $("today").textContent = new Intl.DateTimeFormat("id-ID", { weekday: "long", day: "numeric", month: "long", year: "numeric" }).format(new Date());
  }

  function renderLive(n) {
    const cur = (state.dashboard?.today_schedules || []).find((c) => cardStatus(c, n) === "live");
    const card = $("liveCard");
    if (!cur) { card.hidden = true; return; }
    card.hidden = false;
    $("liveTitle").textContent = cur.subject_name;
    const online = isOnline(cur);
    $("liveMeta").textContent =
      (online ? "Daring" : cur.room) + " · " + (cur.lecturer_name || "");
    const join = $("liveJoin");
    join.hidden = !online;
    if (online) {
      join.href = cur.meeting_url;
      join.innerHTML = '<i class="ph-fill ph-video-camera" aria-hidden="true"></i> Gabung kelas daring';
    }
    $("liveStart").textContent = "Mulai " + fmt(cur.start_time);
    $("liveEnd").textContent = "Selesai " + fmt(cur.end_time);
    const s = toMin(cur.start_time), e = toMin(cur.end_time);
    const pct = Math.min(100, Math.max(0, ((n - s) / Math.max(1, e - s)) * 100));
    requestAnimationFrame(() => { $("liveFill").style.width = pct.toFixed(1) + "%"; });
    const left = Math.max(0, Math.round(e - n));
    const h = Math.floor(left / 60), m = Math.round(left % 60);
    $("liveRemain").textContent = "Sisa " + (h > 0 ? h + "j " : "") + m + "m";
  }

  function classCard(c, st) {
    const badges = { done: "Selesai", live: "Berlangsung", off: "Dibatalkan" };
    const online = isOnline(c);
    const makeup = c.is_makeup ? '<span class="badge online">Kelas pengganti</span>' : "";
    const ov = c.override;
    const resched = !c.is_makeup && ov && ov.status === "RESCHEDULED"
      ? '<span class="badge cancel">' + (ov.new_date ? "Dipindah" : "Diubah") + "</span>"
      : !c.is_makeup && ov && (ov.new_start_time || ov.new_room) ? '<span class="badge cancel">Diubah</span>' : "";
    return (
      '<div class="time num">' + fmt(c.start_time) + "</div>" +
      '<div class="rail"><span class="node"></span></div>' +
      '<div class="card">' +
        '<div class="row1"><h4>' + esc(c.subject_name) + ' <span class="kbadge">' + esc(c.target_group) + "</span></h4>" +
        makeup + resched +
        (online ? '<span class="badge online">Daring</span>' : "") +
        (st !== "next" && st !== "live" ? '<span class="badge ' + (st === "off" ? "cancel" : "done") + '">' + badges[st] + "</span>" : "") +
        "</div>" +
        '<p class="meta">' + (c.type === "PRACTICUM" ? "Praktikum · " : "") +
          fmt(c.start_time) + " - " + fmt(c.end_time) + " · " +
          (online ? "Daring" : esc(c.room || "-")) + " · " + esc(c.lecturer_name || "-") + "</p>" +
      "</div>"
    );
  }
  const itemClass = (c) => "item " + cardStatus(c, t()) + (isOnline(c) ? " online" : "");

  function renderToday(n) {
    const all = state.dashboard?.today_schedules || [];
    const tl = $("tl");
    tl.innerHTML = "";
    const items = all
      .filter((c) => c.status !== "CANCELLED" && n < toMin(c.end_time) + GRACE)
      .sort((a, b) => toMin(a.start_time) - toMin(b.start_time));
    for (const c of items) {
      const el = document.createElement("div");
      el.className = itemClass(c);
      el.innerHTML = classCard(c, cardStatus(c, n));
      tl.appendChild(el);
    }
    $("count").textContent = items.length + " kelas";
    const none = items.length === 0;
    $("empty").style.display = none ? "block" : "none";
    tl.style.display = none ? "none" : "flex";
    if (none) {
      const spent = all.length > 0;
      $("empty").classList.toggle("win", spent);
      $("empty").querySelector("i").className = spent ? "ph-fill ph-check-circle" : "ph ph-calendar-x";
      $("empty").querySelector("h4").textContent = spent ? "Kelas hari ini sudah tuntas" : "Belum ada jadwal";
      $("empty").querySelector("p").textContent = spent ? "Semua jadwal sudah selesai atau terlewat. Selamat beristirahat!" : "Nikmati harimu, tidak ada kelas hari ini.";
    }
  }

  function dueLabel(task) {
    if (task.is_completed) return '<span class="due ok">Selesai</span>';
    if (task.is_overdue) return '<span class="due urgent">Terlambat</span>';
    const dl = new Date(task.deadline);
    const isToday = dl.toISOString().slice(0, 10) === state.todayKey;
    const hhmm = String(dl.getHours()).padStart(2, "0") + "." + String(dl.getMinutes()).padStart(2, "0");
    if (isToday) {
      const urgent = task.urgency === "urgent" ? " urgent" : "";
      return '<span class="due num' + urgent + '">Hari ini ' + hhmm + "</span>";
    }
    return '<span class="due num">' + dl.toLocaleDateString("id-ID", { day: "numeric", month: "short" }) + " · " + hhmm + "</span>";
  }

  function renderTasksHome() {
    const pending = state.tasks.filter((x) => !x.is_completed).sort((a, b) => new Date(a.deadline) - new Date(b.deadline)).slice(0, 5);
    $("taskCount").textContent = pending.length > 0 ? pending.length + " aktif" : "beres semua";
    $("taskEmpty").hidden = pending.length > 0;
    const list = $("taskList");
    list.innerHTML = "";
    for (const task of pending) {
      const row = document.createElement("div");
      row.className = "task-row";
      row.innerHTML = '<div class="tx"><b>' + esc(task.title) + "</b><span>" + esc(task.subject_name) + "</span></div>" + dueLabel(task);
      list.appendChild(row);
    }
  }

  /* ============ Render: Jadwal ============ */
  let selectedDay = todayIso;

  function renderJadwal(animate) {
    const week = state.week;
    if (!week) return;
    if (!DAY_ORDER.includes(selectedDay)) selectedDay = week.current_day || todayIso;

    const chips = $("chips");
    chips.innerHTML = "";
    const mon = new Date(week.week_start + "T00:00:00");
    $("jdWeek").textContent = new Intl.DateTimeFormat("id-ID", { month: "long", year: "numeric" }).format(mon);

    DAY_ORDER.forEach((d, i) => {
      const dt = new Date(mon);
      dt.setDate(mon.getDate() + i);
      const b = document.createElement("button");
      b.type = "button";
      b.className = "chip" + (d === (week.current_day || todayIso) ? " today" : "") + (d === selectedDay ? " sel" : "");
      b.innerHTML = '<span class="dl">' + DAY_ABBR[d] + '</span><span class="dn num">' + dt.getDate() + "</span>";
      b.addEventListener("click", () => {
        if (selectedDay === d) return;
        selectedDay = d;
        vib(6);
        renderJadwal(true);
      });
      chips.appendChild(b);
    });
    const sel = chips.querySelector(".sel");
    if (sel) chips.scrollLeft = sel.offsetLeft - chips.offsetLeft - (chips.clientWidth - sel.offsetWidth) / 2;

    const n = t();
    const isToday = selectedDay === (week.current_day || todayIso);
    const list = [...(week.weekly[selectedDay] || []), ...(week.makeup[selectedDay] || [])]
      .sort((a, b) => toMin(a.start_time) - toMin(b.start_time));

    const tl = $("jdTl");
    tl.innerHTML = "";
    let jp = 0;
    list.forEach((c, idx) => {
      const st = cardStatus(c, isToday ? n : -1);
      if (c.status !== "CANCELLED") jp += Math.round((toMin(c.end_time) - toMin(c.start_time)) / 50);
      const el = document.createElement("div");
      el.className = "item " + st + (isOnline(c) ? " online" : "");
      el.innerHTML = classCard(c, st);
      if (animate) { el.classList.add("rise"); el.style.setProperty("--d", (idx * 0.05).toFixed(2) + "s"); }
      tl.appendChild(el);
    });
    const none = list.length === 0;
    $("jdEmpty").style.display = none ? "block" : "none";
    tl.style.display = none ? "none" : "flex";
    $("jdSum").hidden = none;
    if (!none) $("jdSum").textContent = list.length + " kelas · " + jp + " JP";

    // Tombol ubah jadwal: PJ/Admin; titik merah bila ada override aktif minggu ini
    const canOv = state.user?.is_pj || state.user?.is_admin;
    $("btnOv").hidden = !canOv;
    const hasOv = Object.values(week.weekly).concat(Object.values(week.makeup))
      .flat().some((c) => c.override && c.override.status !== "NORMAL");
    $("ovPing").style.display = hasOv ? "" : "none";
  }

  /* ============ Render: Tugas ============ */
  // Tugas yang sudah selesai & deadline-nya lewat lebih dari N hari dianggap arsip:
  // tidak ikut memenuhi daftar utama, tapi tetap bisa dibuka lewat chip "Arsip".
  const TG_ARCHIVE_DAYS = 3;
  let tgFilter = "aktif";

  function isArchivedTask(task) {
    if (!task.is_completed) return false;
    const dl = new Date(task.deadline);
    if (isNaN(dl)) return false;
    return dl.getTime() < Date.now() - TG_ARCHIVE_DAYS * 864e5;
  }

  function renderTugas() {
    const all = state.tasks;
    const open = all.filter((x) => !x.is_completed);
    const archived = all.filter(isArchivedTask).sort((a, b) => new Date(b.deadline) - new Date(a.deadline));
    const selesai = all.length - open.length - archived.length;
    $("tgSum").textContent =
      open.length + " aktif · " + selesai + " selesai" + (archived.length ? " · " + archived.length + " arsip" : "");

    // Chip arsip hanya muncul kalau memang ada tumpukan tugas lama.
    const archBtn = $("tgArchive");
    archBtn.hidden = archived.length === 0;
    if (archBtn.hidden && tgFilter === "arsip") {
      tgFilter = "aktif";
      [...$("fchips").children].forEach((c) => c.classList.toggle("sel", c.dataset.f === "aktif"));
    }
    archBtn.textContent = "Arsip · " + archived.length;

    const filtered = all
      .filter((x) => {
        if (tgFilter === "semua") return true;
        if (tgFilter === "aktif") return !x.is_completed;
        if (tgFilter === "arsip") return isArchivedTask(x);
        return x.is_completed && !isArchivedTask(x); // selesai (baru)
      })
      .sort((a, b) => (a.is_completed - b.is_completed) || (new Date(a.deadline) - new Date(b.deadline)));
    const list = $("tgList");
    list.innerHTML = "";
    for (const task of filtered) {
      const row = document.createElement("button");
      row.type = "button";
      row.className = "tg-row" + (task.is_completed ? " done" : "");
      row.innerHTML =
        '<span class="chk" aria-hidden="true"><i class="ph-bold ph-check"></i></span>' +
        '<span class="tx"><span class="nm">' + esc(task.title) + '</span><span class="cs">' + esc(task.subject_name) + "</span></span>" +
        dueLabel(task);
      row.addEventListener("click", () => toggleTask(task));
      list.appendChild(row);
    }
    const eg = $("tgEmpty");
    eg.hidden = filtered.length > 0;
    if (!filtered.length) {
      const empty = {
        aktif: ["ph-fill ph-check-circle", "Semua beres", "Tidak ada tugas aktif, kerja bagus."],
        selesai: ["ph ph-clipboard-text", "Belum ada yang selesai", "Tugas yang kamu ceklis akan muncul di sini."],
        arsip: ["ph ph-archive", "Arsip kosong", "Tugas lama yang sudah selesai akan dikumpulkan di sini."],
        semua: ["ph ph-clipboard-text", "Belum ada tugas", "Tugas dari dosen akan muncul di sini."],
      }[tgFilter] || ["ph ph-clipboard-text", "Belum ada tugas", "Tugas dari dosen akan muncul di sini."];
      eg.querySelector("i").className = empty[0];
      eg.querySelector("h4").textContent = empty[1];
      eg.querySelector("p").textContent = empty[2];
    }
  }

  async function loadTasks() {
    const { ok, data } = await api("/api/tasks");
    if (!ok) return;
    state.tasks = data.tasks || [];
    state.manageable_subjects = data.manageable_subjects || [];
    renderTugas();
    renderTasksHome();
    renderTugasAdd();
  }

  async function toggleTask(task) {
    vib(task.is_completed ? 6 : 10);
    const { ok, data } = await api("/api/tasks/" + task.id + "/toggle", { method: "POST" });
    if (!ok) { showToast(firstError(data)); return; }
    task.is_completed = data.is_completed;
    showToast(data.message || "Berhasil");
    renderTugas();
    renderTasksHome();
  }

  /* ---- Tambah tugas (PJ/Admin) ---- */
  const TARGET_LABELS = {
    BB_THEORY: "Kelas Teori BB",
    AA_THEORY: "Kelas Teori AA",
    B1_PRACTICUM: "Praktikum B1",
    B2_PRACTICUM: "Praktikum B2",
    A1_PRACTICUM: "Praktikum A1",
    A2_PRACTICUM: "Praktikum A2",
  };
  const dtLocal = (d) => {
    const p = (n) => String(n).padStart(2, "0");
    return d.getFullYear() + "-" + p(d.getMonth() + 1) + "-" + p(d.getDate()) + "T" + p(d.getHours()) + ":" + p(d.getMinutes());
  };

  // Tombol "Tugas" muncul untuk PJ Kelas/Admin (PJ Kelas bebas semua matkul)
  function renderTugasAdd() {
    const btn = $("tgAdd");
    if (!btn) return;
    btn.hidden = !(state.user && state.user.is_pj);
  }

  function sheetTugas() {
    const subs = (state.admin && state.admin.subjects && state.admin.subjects.length)
      ? state.admin.subjects
      : (state.manageable_subjects || []);
    if (!subs.length) { showToast("Belum ada mata kuliah"); return; }
    const u = state.user;
    const ownTheory = u.theory_class + "_THEORY";
    const ownPracticum = u.practicum_group + "_PRACTICUM";
    openAdmSheet(
      '<h3>Tambah tugas</h3>' +
      '<div class="f-group"><label>Mata kuliah</label><select id="tSub">' +
        subs.map((s) => '<option value="' + s.id + '" data-type="' + (s.type || "THEORY") + '">' + esc(s.name) + " (" + esc(s.code) + ")</option>").join("") +
      "</select></div>" +
      '<div class="f-group"><label>Untuk kelas</label><select id="tTg"></select></div>' +
      '<div class="f-group"><label>Judul tugas</label><input id="tTitle" type="text" placeholder="cth: Laporan Praktikum 3" /></div>' +
      '<div class="f-group"><label>Deadline</label><input id="tDl" type="datetime-local" value="' + dtLocal(new Date(Date.now() + 7 * 864e5)) + '" /></div>' +
      '<div class="f-group"><label>Link pengumpulan (opsional)</label><input id="tUrl" type="url" placeholder="https://…" /></div>' +
      '<div class="f-group"><label>Format pengumpulan (opsional)</label><input id="tFmt" type="text" placeholder="cth: PDF · .zip" /></div>' +
      '<button type="button" class="btn-accent" id="tSave">Tambah</button>' +
      '<button type="button" class="btn-ghost" id="fCancel">Batal</button>'
    );
    const selSub = $("tSub");
    const selTg = $("tTg");
    // PJ = mahasiswa: hanya boleh menugaskan ke kelasnya sendiri (teori & praktikum miliknya). Admin bebas semua kelas.
    const managed = state.user.is_admin ? null : [ownTheory, ownPracticum];
    const fillTg = () => {
      const opt = selSub.selectedOptions[0];
      const type = opt ? opt.dataset.type : "THEORY";
      let list = TARGETS_BY_TYPE[type] || TARGETS_BY_TYPE.THEORY;
      if (managed) list = list.filter((t) => managed.includes(t));
      // Default: kelas milik PJ sendiri (turunan kelasnya), fallback pilihan pertama
      const def = list.includes(ownPracticum) ? ownPracticum : list[0];
      selTg.innerHTML = list.map((tg) =>
        '<option value="' + tg + '"' + (tg === def ? " selected" : "") + ">" + (TARGET_LABELS[tg] || tg) + "</option>").join("");
    };
    fillTg();
    selSub.addEventListener("change", fillTg);
    $("tSave").addEventListener("click", async () => {
      const body = {
        subject_id: +selSub.value,
        target_group: selTg.value,
        title: $("tTitle").value.trim(),
        description: null,
        deadline: $("tDl").value ? $("tDl").value + ":00" : "",
        submission_url: $("tUrl").value.trim() || null,
        submission_format: $("tFmt").value.trim() || null,
      };
      if (!body.title) { showToast("Judul tugas wajib diisi"); return; }
      if (!body.deadline) { showToast("Deadline wajib diisi"); return; }
      const { ok, data } = await api("/api/tasks", { method: "POST", body });
      if (!ok) { showToast(firstError(data)); return; }
      closeAdmSheet();
      showToast(data.message || "Tugas ditambahkan");
      vib(10);
      await loadTasks();
    });
    $("fCancel").addEventListener("click", closeAdmSheet);
  }
  $("tgAdd").addEventListener("click", sheetTugas);

  $("fchips").addEventListener("click", (e) => {
    const b = e.target.closest(".fchip");
    if (!b || b.id === "tgAdd") return; // tombol "Tugas" = aksi tambah, bukan filter
    if (b.dataset.f === tgFilter) return;
    tgFilter = b.dataset.f;
    [...$("fchips").children].forEach((c) => c.classList.toggle("sel", c === b));
    vib(6);
    renderTugas();
  });

  /* ============ Profil ============ */
  function renderProfil() {
    const u = state.user;
    if (!u) return;
    $("pfAva").textContent = initialsOf(u.name);
    $("pfName").textContent = u.name;
    const bits = [u.theory_class, u.practicum_group].filter((x) => x && x !== "-");
    $("pfMeta").textContent = "NIU " + u.niu + (bits.length ? " · " + bits.join(" / ") : "");
    const niu = $("pfNiu");
    if (niu) {
      niu.textContent = u.niu;
      $("pfTeori").textContent = u.theory_class || "—";
      $("pfPrak").textContent = u.practicum_group || "—";
      $("pfRole").textContent = u.is_admin ? "Ketua Kelas" : u.is_pj ? "PJ Kelas" : "Mahasiswa";
    }
    $("btnKelola").hidden = !u.is_admin;
    $("btnOv").hidden = !(u.is_pj || u.is_admin);
  }

  /* ============ Panel & sheet ============ */
  function openPanel(id) {
    $(id).classList.add("open");
    $(id).setAttribute("aria-hidden", "false");
    document.body.classList.add("locked");
  }
  function closePanels() {
    document.querySelectorAll(".panel.open").forEach((p) => {
      p.classList.remove("open");
      p.setAttribute("aria-hidden", "true");
    });
    if (!$("sheetOut").classList.contains("open") && !$("sheetAdm").classList.contains("open")) {
      document.body.classList.remove("locked");
    }
  }
  document.querySelectorAll(".panel-back").forEach((b) => b.addEventListener("click", closePanels));
  document.addEventListener("keydown", (e) => { if (e.key === "Escape") { closeAdmSheet(); closePanels(); } });

  /* ---- Sheet keluar ---- */
  function openSheetOut() {
    $("scrimOut").classList.add("open");
    $("sheetOut").classList.add("open");
    document.body.classList.add("locked");
  }
  function closeSheets() {
    $("scrimOut").classList.remove("open");
    $("sheetOut").classList.remove("open");
    document.body.classList.remove("locked");
  }
  $("scrimOut").addEventListener("click", closeSheets);
  $("btnOutNo").addEventListener("click", closeSheets);
  $("btnOutYes").addEventListener("click", async () => {
    await api("/api/auth/logout", { method: "POST" }).catch(() => {});
    localStorage.removeItem("sk-role");
    location.href = "/app/index.html";
  });

  document.querySelectorAll(".menu-item.soon").forEach((b) => {
    b.addEventListener("click", () => {
      if (b.dataset.panel === "notif") renderNotif();
      openPanel("panel-" + b.dataset.panel);
    });
  });
  $("btnLogout").addEventListener("click", openSheetOut);

  /* ---- Tema ---- */
  const segTheme = $("segTheme");
  function applyTheme(v) {
    localStorage.setItem("sk-theme", v);
    if (v === "sistem") delete document.documentElement.dataset.theme;
    else document.documentElement.dataset.theme = v;
    [...segTheme.children].forEach((b) => b.classList.toggle("sel", b.dataset.t === v));
  }
  segTheme.addEventListener("click", (e) => {
    const b = e.target.closest("button[data-t]");
    if (!b) return;
    applyTheme(b.dataset.t);
    vib(6);
  });
  applyTheme(localStorage.getItem("sk-theme") || "sistem");

  /* ---- Notifikasi (panel) ---- */
  function renderNotif() {
    const week = state.week;
    if (!week) return;
    const acts = Object.values(week.weekly).concat(Object.values(week.makeup))
      .flat()
      // Kartu master & kartu pengganti berbagi objek override yang sama →
      // cukup satu entri notifikasi per override (ambil kartu master saja).
      .filter((c) => !c.is_makeup && c.override && c.override.status && c.override.status !== "NORMAL")
      .map((c) => ({ c, o: c.override }));
    const box = $("notifList");
    box.innerHTML = "";
    const LABEL = { RESCHEDULED: "dijadwalkan ulang", MAKEUP_CLASS: "kelas pengganti", CANCELLED: "dibatalkan", ONLINE: "jadi daring" };
    for (const { c, o } of acts) {
      const label = o.original_date === state.todayKey ? "hari ini" : o.original_date;
      const target = o.new_date ? " → " + new Intl.DateTimeFormat("id-ID", { weekday: "short", day: "numeric", month: "short" }).format(new Date(o.new_date + "T00:00:00")) : "";
      const row = document.createElement("div");
      row.className = "set-row";
      row.innerHTML =
        '<div class="sx"><b>' + esc(c.subject_name) + ' <span class="kbadge">' + esc(c.target_group) + "</span> " + esc(LABEL[o.status] || o.status) + " " + esc(label) + esc(target) + "</b>" +
        "<span>" + (o.reason ? esc(o.reason) : "Status: " + esc(o.status)) + "</span></div>" +
        '<i class="ph-fill ph-swap" style="color: var(--info); font-size: 20px;" aria-hidden="true"></i>';
      box.appendChild(row);
    }
    $("notifEmpty").hidden = acts.length > 0;
    $("bellPing").style.display = acts.length > 0 ? "" : "none";
  }
  $("btnBell").addEventListener("click", () => { renderNotif(); openPanel("panel-notif"); vib(6); });
  $("btnOv").addEventListener("click", sheetOverrides);

  /* ---- Switch ---- */
  function bindSwitch(id, key, def, onChange) {
    const el = $(id);
    const saved = localStorage.getItem(key);
    const on = saved === null ? def : saved === "on";
    el.classList.toggle("on", on);
    el.addEventListener("click", () => {
      const now = !el.classList.contains("on");
      el.classList.toggle("on", now);
      localStorage.setItem(key, now ? "on" : "off");
      vib(8);
      if (onChange) onChange(now);
    });
  }
  bindSwitch("swAnim", "sk-anim", true, (on) => document.documentElement.classList.toggle("no-anim", !on));
  bindSwitch("swVib", "sk-vib", true);
  bindSwitch("swNotJadwal", "sk-notif-jadwal", true);
  bindSwitch("swNotTugas", "sk-notif-tugas", true);
  bindSwitch("swNotInfo", "sk-notif-info", false);

  /* ============ Navigasi tab ============ */
  const tabs = [...document.querySelectorAll(".nav .tab")];
  function setTab(tab) {
    for (const tb of tabs) {
      const on = tb === tab;
      tb.classList.toggle("active", on);
      if (tb.dataset.icon) tb.querySelector("i").className = (on ? "ph-fill ph-" : "ph ph-") + tb.dataset.icon;
    }
  }
  const VIEW_IDS = ["home", "jadwal", "tugas", "profil"];
  tabs.forEach((tb) => {
    tb.addEventListener("click", () => {
      if (tb.classList.contains("active")) return;
      setTab(tb);
      const v = tb.dataset.view;
      if (v === "jadwal") renderJadwal(true);
      if (v === "tugas") renderTugas();
      if (v === "profil") renderProfil();
      for (const id of VIEW_IDS) $("view-" + id).hidden = id !== v;
      window.scrollTo(0, 0);
      vib(6);
    });
  });

  /* ============ Ubah jadwal (override daring) — PJ/Admin ============ */
  function openAdmSheet(html) {
    $("admForm").innerHTML = html;
    $("scrimAdm").classList.add("open");
    $("sheetAdm").classList.add("open");
    document.body.classList.add("locked");
  }
  function closeAdmSheet() {
    $("scrimAdm").classList.remove("open");
    $("sheetAdm").classList.remove("open");
    if (!document.querySelector(".panel.open")) document.body.classList.remove("locked");
  }
  $("scrimAdm").addEventListener("click", closeAdmSheet);

  const fmtDayLong = (key) => new Intl.DateTimeFormat("id-ID", { weekday: "long", day: "numeric", month: "long" }).format(new Date(key + "T00:00:00"));

  function sheetOverrides() {
    const week = state.week;
    if (!week) return;
    const day = selectedDay;
    const key = dateKeyOf(day);
    const list = [...(week.weekly[day] || []), ...(week.makeup[day] || [])].sort((a, b) => toMin(a.start_time) - toMin(b.start_time));
    const dayLabel = key === state.todayKey ? "hari ini" : fmtDayLong(key);
    openAdmSheet(
      '<h3>Ubah jadwal ' + esc(dayLabel) + "</h3>" +
      '<p class="note" style="margin: 0 0 12px;">Pilih mata kuliah untuk dijadikan daring dadakan atau dipindah ke hari lain. Berlaku untuk tanggal ' + esc(key) + " saja; jadwal mingguan otomatis pulih minggu depan. Mahasiswa akan diberi tahu via WhatsApp.</p>" +
      (list.length
        ? list.map((c) => {
            const on = c.status === "ONLINE";
            return (
              '<div class="set-row ov-row" data-id="' + c.id + '">' +
                '<div class="sx"><b>' + esc(c.subject_name) + ' <span class="kbadge">' + esc(c.target_group) + "</span>" + (on ? ' <span class="badge online">Daring</span>' : "") + "</b>" +
                "<span>" + fmt(c.start_time) + " - " + fmt(c.end_time) + " · " + esc(c.room || "-") + "</span></div>" +
                (on
                  ? '<button type="button" class="btn-ghost ov-open" data-url="' + esc(c.meeting_url) + '">Buka</button>' +
                    '<button type="button" class="btn-danger ov-del">Batalkan</button>'
                  : '<button type="button" class="btn-ghost ov-pick">Daring</button>' +
                    '<button type="button" class="btn-ghost ov-move">Pindah</button>') +
              "</div>"
            );
          }).join("")
        : '<p class="mini-note">Tidak ada jadwal pada hari ini.</p>') +
      '<button type="button" class="btn-ghost" id="fCancel">Tutup</button>'
    );
    $("sheetAdm").querySelectorAll(".ov-pick").forEach((b) => {
      const r = b.closest(".ov-row");
      b.addEventListener("click", () => {
        const c = list.find((x) => x.id === +r.dataset.id);
        sheetDaring(c, key);
      });
    });
    $("sheetAdm").querySelectorAll(".ov-move").forEach((b) => {
      const r = b.closest(".ov-row");
      b.addEventListener("click", () => {
        const c = list.find((x) => x.id === +r.dataset.id);
        sheetResched(c, key);
      });
    });
    $("sheetAdm").querySelectorAll(".ov-open").forEach((b) =>
      b.addEventListener("click", () => window.open(b.dataset.url, "_blank", "noopener")));
    $("sheetAdm").querySelectorAll(".ov-del").forEach((b) =>
      b.addEventListener("click", async () => {
        const r = b.closest(".ov-row");
        b.disabled = true;
        const { ok, data } = await api("/api/schedules/override", {
          method: "POST",
          body: { schedule_id: +r.dataset.id, original_date: key, status: "NORMAL", send_waha_blast: true },
        });
        if (!ok) { showToast(firstError(data)); b.disabled = false; return; }
        showToast(data.message || "Jadwal kembali normal");
        vib(10);
        await loadWeek();
        closeAdmSheet();
        renderNotif();
        renderAll();
      }));
    $("fCancel").addEventListener("click", closeAdmSheet);
  }

  function sheetDaring(c, key) {
    if (!c) return;
    openAdmSheet(
      '<h3>' + esc(c.subject_name) + " · " + esc(c.target_group) + " · daring</h3>" +
      '<p class="note" style="margin: 0 0 12px;">Berlaku hanya untuk ' + esc(key) + ". Blast WhatsApp akan dikirim ke grup kelas.</p>" +
      '<div class="f-group"><label>Link kelas</label><input id="fLink" type="url" value="' + esc(c.meeting_url || "") + '" placeholder="https://meet.google.com/…" /></div>' +
      '<div class="f-group"><label>Catatan (opsional)</label><input id="fReason" type="text" placeholder="cth: Dosen di luar kota" /></div>' +
      '<button type="button" class="btn-accent" id="fGo"><i class="ph-fill ph-paper-plane-tilt"></i>&nbsp; Simpan &amp; blast</button>' +
      '<button type="button" class="btn-ghost" id="fBack">Kembali</button>' +
      '<button type="button" class="btn-ghost" id="fCancel">Tutup</button>'
    );
    $("fGo").addEventListener("click", async () => {
      const link = $("fLink").value.trim();
      if (!/^https?:\/\/.+/.test(link)) { showToast("Link kelas wajib diisi (http/https)"); return; }
      const { ok, data } = await api("/api/schedules/override", {
        method: "POST",
        body: {
          schedule_id: c.id,
          original_date: key,
          status: "ONLINE",
          meeting_url: link,
          reason: $("fReason").value.trim() || null,
          send_waha_blast: true,
        },
      });
      if (!ok) { showToast(firstError(data)); return; }
      showToast(data.message || "Pengumuman terkirim");
      vib(10);
      await loadWeek();
      closeAdmSheet();
      renderNotif();
      renderAll();
    });
    $("fBack").addEventListener("click", sheetOverrides);
    $("fCancel").addEventListener("click", closeAdmSheet);
  }

  function sheetResched(c, key) {
    if (!c) return;
    openAdmSheet(
      '<h3>' + esc(c.subject_name) + " · " + esc(c.target_group) + " · pindah hari</h3>" +
      '<p class="note" style="margin: 0 0 12px;">Pertemuan ' + esc(fmtDayLong(key)) + " dianggap diganti, lalu muncul kartu kelas pengganti pada tanggal baru. Jadwal mingguan tidak diubah — minggu depan pulih sendiri.</p>" +
      '<div class="f-group"><label for="fNd">Tanggal pengganti</label><input id="fNd" type="date" /></div>' +
      '<div class="f-grid2">' +
        '<div class="f-group"><label for="fNs">Jam mulai (opsional)</label><input id="fNs" type="time" value="' + esc(c.start_time || "") + '" /></div>' +
        '<div class="f-group"><label for="fNe">Jam selesai (opsional)</label><input id="fNe" type="time" value="' + esc(c.end_time || "") + '" /></div>' +
      "</div>" +
      '<div class="f-group"><label for="fNr">Ruang (opsional)</label><input id="fNr" type="text" value="' + esc(c.room || "") + '" /></div>' +
      '<div class="f-group"><label for="fNReason">Catatan (opsional)</label><input id="fNReason" type="text" placeholder="cth: Dosen berhalangan, kelas dipindah" /></div>' +
      '<button type="button" class="btn-accent" id="fGo"><i class="ph-fill ph-paper-plane-tilt"></i>&nbsp; Simpan &amp; blast</button>' +
      '<button type="button" class="btn-ghost" id="fBack">Kembali</button>' +
      '<button type="button" class="btn-ghost" id="fCancel">Tutup</button>'
    );
    $("fGo").addEventListener("click", async () => {
      const nd = $("fNd").value;
      if (!nd) { showToast("Tanggal pengganti wajib diisi"); return; }
      const ns = $("fNs").value || null;
      const ne = $("fNe").value || null;
      if ((ns && !ne) || (!ns && ne)) { showToast("Jam mulai & selesai harus diisi lengkap, atau kosongkan keduanya"); return; }
      const { ok, data } = await api("/api/schedules/override", {
        method: "POST",
        body: {
          schedule_id: c.id,
          original_date: key,
          status: "RESCHEDULED",
          new_date: nd,
          new_start_time: ns,
          new_end_time: ne,
          new_room: $("fNr").value.trim() || null,
          reason: $("fNReason").value.trim() || null,
          send_waha_blast: true,
        },
      });
      if (!ok) { showToast(firstError(data)); return; }
      showToast(data.message || "Perubahan jadwal tersimpan");
      vib(10);
      await loadWeek();
      closeAdmSheet();
      renderNotif();
      renderAll();
    });
    $("fBack").addEventListener("click", sheetOverrides);
    $("fCancel").addEventListener("click", closeAdmSheet);
  }

  /* ============ Kelola Kelas (admin) ============ */
  const ROLE_LABEL = { STUDENT: "Mahasiswa", PJ: "PJ Kelas", ADMIN: "Ketua Kelas" };

  async function loadAdmin() {
    const { ok, data } = await api("/api/admin");
    if (!ok) { showToast(firstError(data)); return; }
    state.admin = data;
    $("botEndpoint").value = data.waha_settings.waha_base_url || "";
    $("botSession").value = data.waha_settings.waha_session || "";
    $("botKey").value = data.waha_settings.waha_api_key || "";
    // Konfigurasi tersimpan → tampilkan status + pemetaan grup tanpa harus hubungkan ulang
    if (data.waha_settings.waha_base_url && data.waha_settings.waha_session) {
      setBot("warn", "Terkonfigurasi", "Session \"" + data.waha_settings.waha_session + "\" · klik Hubungkan untuk cek koneksi & tarik grup");
      $("grpWrap").hidden = false;
      renderGroups();
    } else {
      setBot("off", "Belum terhubung", "Isi konfigurasi lalu hubungkan.");
    }
    renderMhs($("admQ").value.trim().toLowerCase());
    renderMapel();
  }
  $("btnKelola").addEventListener("click", async () => { openPanel("panel-kelola"); await loadAdmin(); });

  function renderMhs(q) {
    const list = $("mhsList");
    if (!state.admin) return;
    const f = state.admin.users.filter((s) =>
      !q || s.name.toLowerCase().includes(q) || s.niu.includes(q));
    list.innerHTML = f.length ? "" : '<p class="mini-note" style="padding: 12px 0; text-align: center;">Tidak ada hasil.</p>';
    for (const s of f) {
      const row = document.createElement("button");
      row.type = "button";
      row.className = "row-card";
      row.style.cssText = "width: 100%; text-align: left; background: none;";
      row.innerHTML =
        '<span class="row-ava">' + initialsOf(s.name) + "</span>" +
        '<span class="row-tx"><b>' + esc(s.name) + '</b><span>NIU ' + esc(s.niu) + " · " + esc([s.theory_class, s.practicum_group].filter(Boolean).join("/")) + (s.is_active ? "" : " · belum aktif") + "</span></span>" +
        '<span class="chip-role' + (s.role !== "STUDENT" ? " pj" : "") + '">' + (ROLE_LABEL[s.role] || s.role) + "</span>";
      row.addEventListener("click", () => sheetMhs(s));
      list.appendChild(row);
    }
  }

  function sheetMhs(s) {
    const isNew = !s;
    s = s || { name: "", niu: "", role: "STUDENT", theory_class: "BB", practicum_group: "B1" };
    const opt = (v, cur, label) => '<option value="' + v + '"' + (cur === v ? " selected" : "") + ">" + label + "</option>";
    openAdmSheet(
      '<h3>' + (isNew ? "Tambah mahasiswa" : esc(s.name)) + "</h3>" +
      '<div class="f-group"><label>Nama</label><input id="fNama" type="text" value="' + esc(s.name) + '" placeholder="Nama lengkap" /></div>' +
      '<div class="f-group"><label>NIU</label><input id="fNiu" type="text" inputmode="numeric" value="' + esc(s.niu) + '" placeholder="22105100xx" /></div>' +
      '<div class="f-grid2">' +
        '<div class="f-group"><label>Kelas teori</label><select id="fTeori">' + opt("BB", s.theory_class, "BB") + opt("AA", s.theory_class, "AA") + "</select></div>" +
        '<div class="f-group"><label>Grup praktikum</label><select id="fPrak">' + ["B1", "B2", "A1", "A2"].map((k) => opt(k, s.practicum_group, k)).join("") + "</select></div>" +
      "</div>" +
      '<div class="f-group"><label>Role</label><select id="fRole">' + opt("STUDENT", s.role, "Mahasiswa") + opt("PJ", s.role, "PJ Kelas") + opt("ADMIN", s.role, "Ketua Kelas") + "</select></div>" +
      '<p class="mini-note" id="fPjNote" style="display:none">PJ Kelas bisa override jadwal &amp; tambah tugas untuk semua mata kuliah di kelas teori &amp; kloter praktikumnya sendiri.</p>' +
      '<button type="button" class="btn-accent" id="fSave">' + (isNew ? "Tambah" : "Simpan") + "</button>" +
      (!isNew
        ? '<button type="button" class="btn-ghost" id="fReset">Reset PIN</button>' +
          '<button type="button" class="btn-danger" id="fDel">Hapus</button>' +
          '<button type="button" class="btn-ghost" id="fCancel">Batal</button>'
        : '<button type="button" class="btn-ghost" id="fCancel">Batal</button>')
    );
    const pjNote = $("fPjNote");
    const syncPjNote = () => { pjNote.style.display = $("fRole").value === "PJ" ? "" : "none"; };
    $("fRole").addEventListener("change", syncPjNote);
    syncPjNote();
    $("fSave").addEventListener("click", async () => {
      const body = {
        name: $("fNama").value.trim(),
        niu: $("fNiu").value.trim(),
        role: $("fRole").value,
        theory_class: $("fTeori").value,
        practicum_group: $("fPrak").value,
      };

      if (!body.name || body.niu.length < 1) { showToast("Nama dan NIU wajib diisi"); return; }
      const { ok, data } = await api(isNew ? "/api/admin/users" : "/api/admin/users/" + s.id, {
        method: isNew ? "POST" : "PUT",
        body,
      });
      if (!ok) { showToast(firstError(data)); return; }
      closeAdmSheet();
      await loadAdmin();
      showToast(data.message || "Tersimpan");
      vib(10);
    });
    const rst = $("fReset");
    if (rst) rst.addEventListener("click", async () => {
      const { ok, data } = await api("/api/admin/users/reset-pin", { method: "POST", body: { user_id: s.id } });
      if (!ok) { showToast(firstError(data)); return; }
      closeAdmSheet();
      await loadAdmin();
      showToast(data.message || "PIN direset");
    });
    const del = $("fDel");
    if (del) del.addEventListener("click", async () => {
      const { ok, data } = await api("/api/admin/users/" + s.id, { method: "DELETE" });
      if (!ok) { showToast(firstError(data)); return; }
      closeAdmSheet();
      await loadAdmin();
      showToast(data.message || "Dihapus");
    });
    $("fCancel").addEventListener("click", closeAdmSheet);
  }

  function renderMapel() {
    const list = $("mapelList");
    if (!state.admin) return;
    list.innerHTML = "";
    for (const m of state.admin.subjects) {
      const row = document.createElement("button");
      row.type = "button";
      row.className = "row-card";
      row.style.cssText = "width: 100%; text-align: left; background: none;";
      row.innerHTML =
        '<span class="row-ava"><i class="ph ph-' + (m.type === "PRACTICUM" ? "flask" : "book-open") + '" style="font-size: 19px;"></i></span>' +
        '<span class="row-tx"><b>' + esc(m.name) + ' <span class="chip-role' + (m.type === "PRACTICUM" ? " pj" : "") + '">' + esc(m.code) + "</span></b>" +
        "<span>" + (m.schedules_count || 0) + " jadwal · " + (m.tasks_count || 0) + " tugas</span></span>";
      row.addEventListener("click", () => sheetMapel(m));
      list.appendChild(row);
    }
  }

  function sheetMapel(m) {
    const isNew = !m;
    m = m || { code: "", name: "", type: "THEORY" };
    openAdmSheet(
      '<h3>' + (isNew ? "Tambah matkul" : esc(m.name)) + "</h3>" +
      '<div class="f-group"><label>Nama matkul</label><input id="fNama" type="text" value="' + esc(m.name) + '" placeholder="cth: Pemrograman Web" /></div>' +
      '<div class="f-group"><label>Kode</label><input id="fCode" type="text" value="' + esc(m.code) + '" placeholder="CS3604" /></div>' +
      '<div class="f-group"><label>Jenis</label><select id="fJenis"><option value="THEORY"' + (m.type !== "PRACTICUM" ? " selected" : "") + '>Teori</option><option value="PRACTICUM"' + (m.type === "PRACTICUM" ? " selected" : "") + '>Praktikum</option></select></div>' +
      '<button type="button" class="btn-accent" id="fSave">' + (isNew ? "Tambah" : "Simpan") + "</button>" +
      (!isNew ? '<button type="button" class="btn-danger" id="fDel">Hapus</button><button type="button" class="btn-ghost" id="fCancel">Batal</button>'
              : '<button type="button" class="btn-ghost" id="fCancel">Batal</button>')
    );
    $("fSave").addEventListener("click", async () => {
      const body = { name: $("fNama").value.trim(), code: $("fCode").value.trim(), type: $("fJenis").value };
      if (!body.name || !body.code) { showToast("Nama dan kode matkul wajib diisi"); return; }
      const { ok, data } = await api(isNew ? "/api/admin/subjects" : "/api/admin/subjects/" + m.id, {
        method: isNew ? "POST" : "PUT",
        body,
      });
      if (!ok) { showToast(firstError(data)); return; }
      closeAdmSheet();
      await loadAdmin();
      showToast(data.message || "Tersimpan");
      vib(10);
    });
    const del = $("fDel");
    if (del) del.addEventListener("click", async () => {
      const { ok, data } = await api("/api/admin/subjects/" + m.id, { method: "DELETE" });
      if (!ok) { showToast(firstError(data)); return; }
      closeAdmSheet();
      await loadAdmin();
      showToast(data.message || "Dihapus");
    });
    $("fCancel").addEventListener("click", closeAdmSheet);
  }

  $("btnAddMhs").addEventListener("click", () => sheetMhs(null));
  $("btnAddMapel").addEventListener("click", () => sheetMapel(null));
  $("admQ").addEventListener("input", (e) => renderMhs(e.target.value.trim().toLowerCase()));

  /* ---- Kelola Jadwal per matkul ---- */
  const TARGETS_BY_TYPE = {
    THEORY: ["BB_THEORY", "AA_THEORY"],
    PRACTICUM: ["B1_PRACTICUM", "B2_PRACTICUM", "A1_PRACTICUM", "A2_PRACTICUM"],
  };
  const DAY_NAMES = { 1: "Senin", 2: "Selasa", 3: "Rabu", 4: "Kamis", 5: "Jumat", 6: "Sabtu", 7: "Minggu" };
  let jdAdminSubject = null;

  function renderJadwalAdmin() {
    if (!state.admin) return;
    const sel = $("jdSubject");
    const subs = state.admin.subjects;
    if (!subs.length) {
      sel.innerHTML = '<option value="">— belum ada matkul —</option>';
      $("jdAdminList").innerHTML = '<p class="mini-note" style="padding: 12px 0; text-align: center;">Tambahkan mata kuliah dulu di tab Matkul.</p>';
      $("btnAddJadwal").disabled = true;
      return;
    }
    $("btnAddJadwal").disabled = false;
    if (!jdAdminSubject || !subs.some((s) => s.id === jdAdminSubject)) jdAdminSubject = subs[0].id;
    sel.innerHTML = subs.map((s) =>
      '<option value="' + s.id + '"' + (s.id === jdAdminSubject ? " selected" : "") + ">" + esc(s.name) + " (" + esc(s.code) + ")</option>").join("");
    renderJadwalAdminList();
  }

  function renderJadwalAdminList() {
    const list = $("jdAdminList");
    const rows = (state.admin.schedules || []).filter((s) => s.subject_id === jdAdminSubject);
    if (!rows.length) {
      list.innerHTML = '<p class="mini-note" style="padding: 12px 0; text-align: center;">Belum ada jadwal untuk matkul ini.</p>';
      return;
    }
    list.innerHTML = "";
    for (const s of rows.sort((a, b) => (a.day_of_week - b.day_of_week) || (toMin(a.start_time) - toMin(b.start_time)))) {
      const row = document.createElement("div");
      row.className = "row-card";
      row.innerHTML =
        '<span class="row-ava"><i class="ph ph-clock" style="font-size: 18px;"></i></span>' +
        '<span class="row-tx"><b>' + esc(DAY_NAMES[s.day_of_week] || "?") + " · " + fmt(s.start_time) + " - " + fmt(s.end_time) + "</b>" +
        "<span>" + esc(s.target_group) + " · " + esc(s.room) + " · " + esc(s.lecturer_name) + "</span></span>" +
        '<button type="button" class="mini-link" data-act="edit">Ubah</button>' +
        '<button type="button" class="mini-link del" data-act="del">Hapus</button>';
      row.querySelector('[data-act="edit"]').addEventListener("click", () => sheetJadwal(s));
      row.querySelector('[data-act="del"]').addEventListener("click", async (e) => {
        e.target.disabled = true;
        const { ok, data } = await api("/api/schedules/" + s.id, { method: "DELETE" });
        if (!ok) { showToast(firstError(data)); e.target.disabled = false; return; }
        showToast(data.message || "Jadwal dihapus");
        vib(10);
        await loadAdmin();
        renderJadwalAdmin();
      });
      list.appendChild(row);
    }
  }
  $("jdSubject").addEventListener("change", (e) => {
    jdAdminSubject = +e.target.value;
    renderJadwalAdminList();
  });

  function sheetJadwal(s) {
    const isNew = !s;
    // Pastikan jdAdminSubject valid (mis. sheet dibuka cepat sebelum render tab selesai)
    const selJd = $("jdSubject");
    if (selJd && selJd.value) jdAdminSubject = +selJd.value;
    const subject = (state.admin.subjects || []).find((x) => x.id === jdAdminSubject) || {};
    const type = subject.type || "THEORY";
    const targets = TARGETS_BY_TYPE[type] || TARGETS_BY_TYPE.THEORY;
    const d = s || { day_of_week: 1, start_time: "", end_time: "", target_group: targets[0], room: "", lecturer_name: "" };
    const dayOpts = [1, 2, 3, 4, 5, 6].map((n) =>
      '<option value="' + n + '"' + (+d.day_of_week === n ? " selected" : "") + ">" + DAY_NAMES[n] + "</option>").join("");
    const tgOpts = targets.map((tg) =>
      '<option value="' + tg + '"' + (d.target_group === tg ? " selected" : "") + ">" + tg + "</option>").join("");
    openAdmSheet(
      '<h3>' + (isNew ? "Tambah jadwal" : "Ubah jadwal") + "</h3>" +
      '<p class="note" style="margin: 4px 0 0;">' + esc(subject.name || "-") + " · kelas " + (type === "PRACTICUM" ? "praktikum" : "teori") + "</p>" +
      '<div class="f-grid2">' +
        '<div class="f-group"><label>Hari</label><select id="jDay">' + dayOpts + "</select></div>" +
        '<div class="f-group"><label>Kelas</label><select id="jTg">' + tgOpts + "</select></div>" +
      "</div>" +
      '<div class="f-grid2">' +
        '<div class="f-group"><label>Mulai</label><input id="jStart" type="time" value="' + esc(d.start_time) + '" /></div>' +
        '<div class="f-group"><label>Selesai</label><input id="jEnd" type="time" value="' + esc(d.end_time) + '" /></div>' +
      "</div>" +
      '<div class="f-group"><label>Ruang</label><input id="jRoom" type="text" value="' + esc(d.room) + '" placeholder="cth: Lab Komputer 2" /></div>' +
      '<div class="f-group"><label>Dosen</label><input id="jLect" type="text" value="' + esc(d.lecturer_name) + '" placeholder="Nama dosen" /></div>' +
      '<div class="form-alert" id="jAlert" hidden><i class="ph-fill ph-warning-circle"></i><span></span></div>' +
      '<button type="button" class="btn-accent" id="jSave">' + (isNew ? "Tambah" : "Simpan") + "</button>" +
      '<button type="button" class="btn-ghost" id="fCancel">Batal</button>'
    );
    $("jSave").addEventListener("click", async () => {
      const body = {
        subject_id: jdAdminSubject,
        target_group: $("jTg").value,
        day_of_week: +$("jDay").value,
        start_time: $("jStart").value,
        end_time: $("jEnd").value,
        room: $("jRoom").value.trim(),
        lecturer_name: $("jLect").value.trim(),
      };
      if (!body.start_time || !body.end_time) { showToast("Jam mulai & selesai wajib diisi"); return; }
      if (!body.room || !body.lecturer_name) { showToast("Ruang & dosen wajib diisi"); return; }
      const { ok, data } = await api(isNew ? "/api/schedules" : "/api/schedules/" + s.id, {
        method: isNew ? "POST" : "PUT",
        body,
      });
      if (!ok) {
        const msg = firstError(data);
        if (/bentrok/i.test(msg)) {
          const al = $("jAlert");
          al.querySelector("span").textContent = msg;
          al.hidden = false;
          al.scrollIntoView({ block: "nearest", behavior: "smooth" });
        } else {
          showToast(msg);
        }
        return;
      }
      closeAdmSheet();
      await loadAdmin();
      renderJadwalAdmin();
      showToast(data.message || "Jadwal tersimpan");
      vib(10);
    });
    $("fCancel").addEventListener("click", closeAdmSheet);
  }
  $("btnAddJadwal").addEventListener("click", () => sheetJadwal(null));

  /* Alert inline bentrok: hilang otomatis saat form diubah lagi */
  document.addEventListener("change", (e) => {
    if (e.target.matches("#jDay, #jTg, #jStart, #jEnd, #jRoom, #jLect")) {
      const al = $("jAlert");
      if (al && !al.hidden) al.hidden = true;
    }
  });


  /* ---- Segmented Kelola ---- */
  let kelTab = "mhs";
  $("segKelola").addEventListener("click", (e) => {
    const b = e.target.closest("button[data-k]");
    if (!b || b.dataset.k === kelTab) return;
    kelTab = b.dataset.k;
    [...$("segKelola").children].forEach((c) => c.classList.toggle("sel", c === b));
    $("kel-mhs").hidden = kelTab !== "mhs";
    $("kel-mapel").hidden = kelTab !== "mapel";
    $("kel-jadwal").hidden = kelTab !== "jadwal";
    $("kel-bot").hidden = kelTab !== "bot";
    if (kelTab === "jadwal") renderJadwalAdmin();
    vib(6);
  });

  /* ---- Bot WA (WAHA) ---- */
  function setBot(dotCls, title, sub) {
    $("botDot").className = "bot-dot " + dotCls;
    $("botStatTitle").textContent = title;
    $("botStatSub").textContent = sub;
  }

  let fetchedGroups = [];
  $("btnConnect").addEventListener("click", async () => {
    const ep = $("botEndpoint").value.trim(), ss = $("botSession").value.trim();
    if (!ep || !ss) { showToast("Endpoint dan nama session wajib diisi"); return; }
    setBot("warn", "Menghubungkan…", "Menghubungi " + ep);
    $("btnConnect").textContent = "Menghubungkan…";
    const { ok, data } = await api("/api/admin/waha/groups?waha_base_url=" + encodeURIComponent(ep) + "&waha_session=" + encodeURIComponent(ss) + "&waha_api_key=" + encodeURIComponent($("botKey").value.trim()));
    $("btnConnect").textContent = "Tarik ulang grup";
    if (!ok) {
      setBot("off", "Gagal terhubung", data.message || "Periksa endpoint & session.");
      showToast(data.message || "Gagal menarik grup");
      return;
    }
    fetchedGroups = data.groups || [];
    setBot("ok", "Terhubung", 'Session "' + ss + '" · ' + fetchedGroups.length + " grup ditemukan");
    renderGroups();
    $("grpWrap").hidden = false;
    vib(10);
    showToast("Bot WA terhubung");
  });

  function renderGroups() {
    const list = $("grpList");
    list.innerHTML = "";
    const cfgs = state.admin?.waha_configs || [];
    const nameOfJid = (jid) => (fetchedGroups.find((g) => (g.id ?? g.jid) === jid) || {}).name || jid;
    for (const cfg of cfgs) {
      const row = document.createElement("div");
      row.className = "grp-row";
      const savedJid = cfg.group_jid || "";
      const inFetched = savedJid && fetchedGroups.some((g) => (g.id ?? g.jid) === savedJid);
      // JID tersimpan tapi tidak ada di daftar grup bot → tetap tampilkan di dropdown
      const savedOpt = savedJid && !inFetched
        ? '<option value="' + esc(savedJid) + '" selected>' + esc(savedJid) + " (tidak ada di daftar bot)</option>"
        : "";
      const opts = '<option value="">— pilih grup —</option>' + savedOpt + fetchedGroups.map((g) =>
        '<option value="' + esc(g.id ?? g.jid ?? "") + '"' + (savedJid && savedJid === (g.jid ?? g.id) ? " selected" : "") + ">" + esc(g.name || g.group_name || "(tanpa nama)") + "</option>").join("");
      const mappedLabel = cfg.group_jid
        ? "✓ " + esc(nameOfJid(cfg.group_jid))
        : "Belum dipetakan";
      row.innerHTML =
        '<span class="grp-tx"><b>' + esc(cfg.target_group) + ' · ' + esc(cfg.group_name) + '</b><span id="gmap-' + cfg.id + '">' +
        mappedLabel + "</span></span>" +
        '<select data-id="' + cfg.id + '" data-old="' + esc(cfg.group_jid || "") + '">' + opts + "</select>";
      row.querySelector("select").addEventListener("change", async (e) => {
        const sel = e.target;
        const jid = sel.value || null;
        const prevLabel = $("gmap-" + cfg.id)?.textContent;
        $("gmap-" + cfg.id).textContent = "Menyimpan…";
        sel.disabled = true;
        const { ok, data } = await api("/api/admin/waha/settings", {
          method: "POST",
          body: {
            waha_base_url: $("botEndpoint").value.trim(),
            waha_session: $("botSession").value.trim(),
            waha_api_key: $("botKey").value.trim() || null,
            groups: [{ id: cfg.id, group_jid: jid }],
          },
        });
        sel.disabled = false;
        if (!ok) {
          showToast(firstError(data) || "Gagal menyimpan pemetaan");
          $("gmap-" + cfg.id).textContent = prevLabel || "Gagal";
          return;
        }
        showToast("Pemetaan grup disimpan");
        vib(6);
        await loadAdmin();          // ambil data terbaru + render ulang otomatis
      });
      list.appendChild(row);
    }
  }
  bindSwitch("swTrgJadwal", "sk-trg-jadwal", true);
  bindSwitch("swTrgTugas", "sk-trg-tugas", true);
  bindSwitch("swTrgInfo", "sk-trg-info", false);

  /* ============ Loop & init ============ */
  function renderAll() {
    renderGreeting();
    renderLive(t());
    renderTasksHome();
    renderToday(t());
    if (!$("view-jadwal").hidden) renderJadwal(false);
    if (!$("view-tugas").hidden) renderTugas();
  }

  // Muat ulang data jadwal mingguan (dipakai setelah override disimpan/dihapus)
  async function loadWeek() {
    const { ok, data } = await api("/api/schedules");
    if (ok) state.week = data;
  }

  async function init() {
    // Auth guard
    const me = await api("/api/me").catch(() => null);
    if (!me || !me.ok) { location.replace("/app/index.html"); return; }
    state.user = me.data.user;

    // Identitas di topbar & profil
    $("tbName").textContent = state.user.name;
    const bits = [state.user.theory_class, state.user.practicum_group].filter((x) => x && x !== "-");
    $("tbMeta").textContent = "NIU " + state.user.niu + (bits.length ? " · " + bits.join(" / ") : "");
    renderProfil();

    // Data paralel
    const [d, w, tk] = await Promise.all([
      api("/api/dashboard"),
      api("/api/schedules"),
      api("/api/tasks"),
    ]);
    if (d.ok) state.dashboard = d.data;
    if (w.ok) state.week = w.data;
    if (tk.ok) {
      state.tasks = tk.data.tasks || [];
      state.manageable_subjects = tk.data.manageable_subjects || [];
    }

    renderAll();
    renderTugasAdd();
    renderNotif();
    if (!demoT) setInterval(() => { state.todayKey = new Date().toISOString().slice(0, 10); renderAll(); }, 30000);
  }

  init();
})();
