<?php
// ══════════════════════════════════════════════════════
// harpy/landing.php — Marketing Landing Page
// Harpy Laundry ERP — Halaman Utama
// ══════════════════════════════════════════════════════
date_default_timezone_set('Asia/Jakarta');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="description" content="LAMASY — Laundry Management System by Harpy. Platform manajemen laundry modern. POS, laporan keuangan, manajemen karyawan, notifikasi WhatsApp, dan lebih banyak lagi."/>
<meta name="keywords" content="software laundry, ERP laundry, aplikasi laundry, POS laundry, manajemen laundry"/>
<title>LAMASY — Laundry Management System by Harpy</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ══════════════════════════════════════════════════════
   CSS Variables & Reset
══════════════════════════════════════════════════════ */
:root {
  --teal:    #35E8D5;
  --teal-d:  #1BC4B3;
  --teal-l:  rgba(53,232,213,.12);
  --teal-g:  rgba(53,232,213,.25);
  --navy:    #1B2D5A;
  --navy-d:  #0F1C3A;
  --navy-m:  #162348;
  --navy-c:  #0B1630;
  --white:   #FFFFFF;
  --gray:    #6C7A8D;
  --font:    'Plus Jakarta Sans', sans-serif;
  --mono:    'DM Mono', monospace;
  --r:       12px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
  font-family: var(--font);
  background: var(--navy-d);
  color: var(--white);
  overflow-x: hidden;
}
a { text-decoration: none; color: inherit; }
img { max-width: 100%; }

/* ══════════════════════════════════════════════════════
   Background Grid
══════════════════════════════════════════════════════ */
.bg-grid {
  position: fixed; inset: 0; pointer-events: none; z-index: 0;
  background-image:
    linear-gradient(rgba(255,255,255,.022) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.022) 1px, transparent 1px);
  background-size: 52px 52px;
}

/* ══════════════════════════════════════════════════════
   Animated Orbs
══════════════════════════════════════════════════════ */
.orb {
  position: absolute; border-radius: 50%; filter: blur(90px); pointer-events: none;
}
.orb-teal {
  width: 500px; height: 500px;
  background: radial-gradient(circle, rgba(53,232,213,.18) 0%, transparent 70%);
  animation: floatOrb 14s ease-in-out infinite;
}
.orb-blue {
  width: 400px; height: 400px;
  background: radial-gradient(circle, rgba(27,45,90,.6) 0%, transparent 70%);
  animation: floatOrb 18s ease-in-out infinite reverse;
}
@keyframes floatOrb {
  0%,100% { transform: translate(0,0); }
  33%      { transform: translate(30px,-25px); }
  66%      { transform: translate(-20px,20px); }
}

/* ══════════════════════════════════════════════════════
   Navbar
══════════════════════════════════════════════════════ */
.navbar {
  position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
  padding: 0 24px;
  height: 68px;
  display: flex; align-items: center; justify-content: space-between;
  background: rgba(15,28,58,.85);
  backdrop-filter: blur(20px);
  border-bottom: 1px solid rgba(255,255,255,.07);
  transition: background .3s;
}
.nav-logo {
  display: flex; align-items: center; gap: 10px;
  font-size: 20px; font-weight: 800; color: var(--white); letter-spacing: .01em;
}
.nav-logo .logo-badge {
  width: 38px; height: 38px;
  background: linear-gradient(135deg, var(--teal), var(--teal-d));
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px;
  box-shadow: 0 4px 16px rgba(53,232,213,.3);
  flex-shrink: 0;
}
.nav-logo span { color: var(--teal); }
.nav-links {
  display: flex; align-items: center; gap: 6px;
  list-style: none;
}
.nav-links a {
  padding: 8px 14px;
  font-size: 14px; font-weight: 500;
  color: rgba(255,255,255,.65);
  border-radius: 8px;
  transition: all .15s;
}
.nav-links a:hover { color: var(--white); background: rgba(255,255,255,.06); }
.nav-actions {
  display: flex; align-items: center; gap: 10px;
}
.btn-login-nav {
  padding: 8px 18px;
  font-size: 14px; font-weight: 600;
  color: rgba(255,255,255,.7);
  border: 1.5px solid rgba(255,255,255,.15);
  border-radius: 9px; cursor: pointer;
  transition: all .15s;
}
.btn-login-nav:hover { color: var(--white); border-color: rgba(255,255,255,.35); background: rgba(255,255,255,.05); }
.btn-cta-nav {
  padding: 9px 20px;
  font-size: 14px; font-weight: 700;
  color: var(--navy-d);
  background: linear-gradient(135deg, var(--teal), var(--teal-d));
  border-radius: 9px; cursor: pointer;
  box-shadow: 0 4px 16px rgba(53,232,213,.25);
  transition: all .15s;
}
.btn-cta-nav:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(53,232,213,.4); }
.nav-toggle {
  display: none;
  background: none; border: none;
  font-size: 22px; cursor: pointer; color: var(--white);
}
.nav-mobile-menu {
  display: none;
  position: fixed; top: 68px; left: 0; right: 0;
  background: rgba(15,28,58,.97);
  backdrop-filter: blur(20px);
  border-bottom: 1px solid rgba(255,255,255,.08);
  padding: 16px 24px 20px;
  flex-direction: column; gap: 4px;
  z-index: 999;
}
.nav-mobile-menu.open { display: flex; }
.nav-mobile-menu a {
  padding: 12px 14px;
  font-size: 15px; font-weight: 500;
  color: rgba(255,255,255,.7);
  border-radius: 9px; transition: all .15s;
}
.nav-mobile-menu a:hover { color: var(--white); background: rgba(255,255,255,.06); }
.nav-mobile-divider { height: 1px; background: rgba(255,255,255,.07); margin: 8px 0; }

/* ══════════════════════════════════════════════════════
   Hero Section
══════════════════════════════════════════════════════ */
.hero {
  position: relative; z-index: 1;
  padding: 140px 24px 80px;
  text-align: center;
  overflow: hidden;
}
.hero-orb-wrap {
  position: absolute; inset: 0; pointer-events: none;
  overflow: hidden;
}
.hero-badge {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 6px 16px; border-radius: 100px;
  background: rgba(53,232,213,.1);
  border: 1px solid rgba(53,232,213,.25);
  font-size: 12px; font-weight: 600; color: var(--teal);
  margin-bottom: 24px;
  letter-spacing: .04em;
}
.hero-badge .dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--teal);
  box-shadow: 0 0 8px var(--teal);
  animation: pulse 2s infinite;
}
@keyframes pulse {
  0%,100% { opacity: 1; transform: scale(1); }
  50%      { opacity: .5; transform: scale(1.3); }
}
.hero h1 {
  font-size: clamp(32px, 5.5vw, 62px);
  font-weight: 800;
  line-height: 1.12;
  max-width: 760px; margin: 0 auto 22px;
  letter-spacing: -.02em;
}
.hero h1 .accent { color: var(--teal); }
.hero-sub {
  font-size: clamp(15px, 2vw, 18px);
  color: rgba(255,255,255,.55);
  max-width: 540px; margin: 0 auto 36px;
  line-height: 1.6;
}
.hero-btns {
  display: flex; align-items: center; justify-content: center;
  gap: 14px; flex-wrap: wrap;
  margin-bottom: 60px;
}
.btn-primary {
  padding: 14px 30px;
  font-family: var(--font); font-size: 15px; font-weight: 700;
  color: var(--navy-d);
  background: linear-gradient(135deg, var(--teal), var(--teal-d));
  border: none; border-radius: 11px; cursor: pointer;
  box-shadow: 0 6px 24px rgba(53,232,213,.3);
  transition: all .2s;
  display: inline-flex; align-items: center; gap: 8px;
}
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 32px rgba(53,232,213,.45); }
.btn-secondary {
  padding: 13px 28px;
  font-family: var(--font); font-size: 15px; font-weight: 600;
  color: rgba(255,255,255,.8);
  background: rgba(255,255,255,.06);
  border: 1.5px solid rgba(255,255,255,.15);
  border-radius: 11px; cursor: pointer;
  transition: all .2s;
  display: inline-flex; align-items: center; gap: 8px;
}
.btn-secondary:hover { color: var(--white); border-color: rgba(255,255,255,.3); background: rgba(255,255,255,.1); transform: translateY(-1px); }

/* Hero mockup dashboard */
.hero-mockup {
  max-width: 860px; margin: 0 auto;
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.1);
  border-radius: 20px;
  overflow: hidden;
  box-shadow: 0 40px 100px rgba(0,0,0,.5), 0 0 0 1px rgba(53,232,213,.1);
  position: relative;
}
.mockup-topbar {
  padding: 14px 18px;
  background: rgba(255,255,255,.04);
  border-bottom: 1px solid rgba(255,255,255,.07);
  display: flex; align-items: center; gap: 8px;
}
.mockup-dot { width: 11px; height: 11px; border-radius: 50%; }
.mockup-dot:nth-child(1) { background: #FF5F57; }
.mockup-dot:nth-child(2) { background: #FEBC2E; }
.mockup-dot:nth-child(3) { background: #28C840; }
.mockup-url {
  margin-left: 12px;
  flex: 1; max-width: 280px;
  padding: 5px 14px;
  background: rgba(255,255,255,.06);
  border-radius: 6px;
  font-family: var(--mono); font-size: 11px;
  color: rgba(255,255,255,.35);
}
.mockup-body {
  display: grid; grid-template-columns: 180px 1fr;
  min-height: 300px;
}
.mockup-sidebar {
  background: rgba(255,255,255,.025);
  border-right: 1px solid rgba(255,255,255,.06);
  padding: 16px 12px;
}
.mockup-sidebar-logo {
  display: flex; align-items: center; gap: 8px;
  margin-bottom: 20px; padding: 0 4px;
}
.mockup-sidebar-logo .mb { width: 24px; height: 24px; border-radius: 6px; background: linear-gradient(135deg, var(--teal), var(--teal-d)); }
.mockup-sidebar-logo span { font-size: 12px; font-weight: 800; }
.mockup-nav-item {
  padding: 8px 10px; border-radius: 7px;
  font-size: 11px; font-weight: 500;
  color: rgba(255,255,255,.4);
  margin-bottom: 3px;
  display: flex; align-items: center; gap: 8px;
}
.mockup-nav-item.active { background: var(--teal-l); color: var(--teal); }
.mockup-nav-item .ni { font-size: 13px; }
.mockup-content { padding: 18px; }
.mockup-content-title { font-size: 13px; font-weight: 800; margin-bottom: 14px; }
.mockup-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 14px; }
.mockup-stat-card {
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.07);
  border-radius: 10px; padding: 10px 12px;
}
.mockup-stat-card .ms-label { font-size: 9px; color: rgba(255,255,255,.35); margin-bottom: 4px; font-weight: 600; }
.mockup-stat-card .ms-val { font-size: 14px; font-weight: 800; }
.mockup-stat-card .ms-val.green { color: var(--teal); }
.mockup-stat-card .ms-val.blue  { color: #7DD3FC; }
.mockup-stat-card .ms-val.orange { color: #FCD34D; }
.mockup-orders { background: rgba(255,255,255,.02); border: 1px solid rgba(255,255,255,.06); border-radius: 10px; overflow: hidden; }
.mockup-order-row {
  display: grid; grid-template-columns: 1fr 70px 70px;
  padding: 8px 12px; font-size: 10px; font-weight: 500;
  border-bottom: 1px solid rgba(255,255,255,.04);
  color: rgba(255,255,255,.6);
  align-items: center;
}
.mockup-order-row.header { font-size: 9px; font-weight: 700; color: rgba(255,255,255,.3); text-transform: uppercase; letter-spacing: .06em; }
.status-badge { padding: 2px 7px; border-radius: 5px; font-size: 9px; font-weight: 700; }
.status-badge.done   { background: rgba(53,232,213,.15); color: var(--teal); }
.status-badge.proc   { background: rgba(253,224,71,.12); color: #FDE047; }
.status-badge.ready  { background: rgba(99,102,241,.15); color: #A5B4FC; }

/* ══════════════════════════════════════════════════════
   Stats Bar
══════════════════════════════════════════════════════ */
.stats-bar {
  position: relative; z-index: 1;
  padding: 0 24px 70px;
}
.stats-inner {
  max-width: 860px; margin: 0 auto;
  background: rgba(53,232,213,.06);
  border: 1px solid rgba(53,232,213,.15);
  border-radius: 16px;
  display: grid; grid-template-columns: repeat(3, 1fr);
  overflow: hidden;
}
.stat-item {
  padding: 28px 24px;
  text-align: center;
  border-right: 1px solid rgba(53,232,213,.1);
}
.stat-item:last-child { border-right: none; }
.stat-num {
  font-size: 36px; font-weight: 800;
  color: var(--teal); font-family: var(--mono);
  margin-bottom: 6px; line-height: 1;
}
.stat-label {
  font-size: 13px; font-weight: 500;
  color: rgba(255,255,255,.55);
}

/* ══════════════════════════════════════════════════════
   Section Shared
══════════════════════════════════════════════════════ */
.section {
  position: relative; z-index: 1;
  padding: 80px 24px;
  max-width: 1100px; margin: 0 auto;
}
.section-header {
  text-align: center;
  margin-bottom: 56px;
}
.section-tag {
  display: inline-block;
  font-family: var(--mono); font-size: 11px; font-weight: 500;
  letter-spacing: .14em; text-transform: uppercase;
  color: var(--teal); margin-bottom: 14px;
}
.section-header h2 {
  font-size: clamp(26px, 4vw, 40px);
  font-weight: 800; letter-spacing: -.02em;
  margin-bottom: 14px;
}
.section-header p {
  font-size: 16px; color: rgba(255,255,255,.5);
  max-width: 520px; margin: 0 auto; line-height: 1.65;
}

/* ══════════════════════════════════════════════════════
   AI Hero Section
══════════════════════════════════════════════════════ */
.ai-section {
  position: relative; z-index: 1;
  padding: 0 24px 80px;
}
.ai-inner {
  max-width: 1100px; margin: 0 auto;
}
.ai-badge-row {
  display: flex; align-items: center; justify-content: center;
  gap: 10px; margin-bottom: 20px;
}
.ai-badge {
  display: inline-flex; align-items: center; gap: 6px;
  background: linear-gradient(135deg, rgba(139,92,246,.18), rgba(99,102,241,.12));
  border: 1px solid rgba(139,92,246,.35);
  border-radius: 100px;
  padding: 5px 14px; font-size: 11px; font-weight: 700;
  letter-spacing: .1em; text-transform: uppercase; color: #C4B5FD;
  font-family: var(--mono);
}
.ai-badge .pulse {
  width: 7px; height: 7px; border-radius: 50%;
  background: #8B5CF6;
  box-shadow: 0 0 6px rgba(139,92,246,.8);
  animation: pulse 1.8s ease-in-out infinite;
}
@keyframes pulse {
  0%,100% { opacity: 1; transform: scale(1); }
  50%      { opacity: .5; transform: scale(.75); }
}
.ai-hero-card {
  background: linear-gradient(135deg,
    rgba(139,92,246,.1) 0%,
    rgba(99,102,241,.08) 40%,
    rgba(27,45,90,.3) 100%);
  border: 1.5px solid rgba(139,92,246,.25);
  border-radius: 24px;
  padding: 56px 48px;
  position: relative; overflow: hidden;
  display: grid; grid-template-columns: 1fr 1fr;
  gap: 48px; align-items: center;
}
.ai-hero-card::before {
  content: '';
  position: absolute; top: -80px; right: -80px;
  width: 360px; height: 360px; border-radius: 50%;
  background: radial-gradient(circle, rgba(139,92,246,.15) 0%, transparent 70%);
  pointer-events: none;
}
.ai-hero-card::after {
  content: '';
  position: absolute; bottom: -60px; left: -60px;
  width: 260px; height: 260px; border-radius: 50%;
  background: radial-gradient(circle, rgba(99,102,241,.12) 0%, transparent 70%);
  pointer-events: none;
}
.ai-hero-text { position: relative; z-index: 1; }
.ai-hero-text h2 {
  font-size: clamp(24px, 3.5vw, 38px);
  font-weight: 800; letter-spacing: -.02em;
  line-height: 1.2; margin-bottom: 16px;
}
.ai-hero-text h2 em {
  font-style: normal;
  background: linear-gradient(135deg, #C4B5FD, #8B5CF6);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.ai-hero-text p {
  font-size: 15px; color: rgba(255,255,255,.55);
  line-height: 1.7; margin-bottom: 28px;
}
.ai-features-list {
  display: flex; flex-direction: column; gap: 12px;
}
.ai-feat-item {
  display: flex; align-items: flex-start; gap: 12px;
}
.ai-feat-icon {
  width: 32px; height: 32px; border-radius: 9px; flex-shrink: 0;
  background: rgba(139,92,246,.15);
  border: 1px solid rgba(139,92,246,.25);
  display: flex; align-items: center; justify-content: center;
  font-size: 15px;
}
.ai-feat-text strong { font-size: 13.5px; font-weight: 700; display: block; margin-bottom: 2px; }
.ai-feat-text span   { font-size: 12px; color: rgba(255,255,255,.4); line-height: 1.4; }

/* AI mockup chat */
.ai-mockup {
  position: relative; z-index: 1;
  background: rgba(15,28,58,.7);
  border: 1px solid rgba(255,255,255,.1);
  border-radius: 16px; overflow: hidden;
  box-shadow: 0 24px 60px rgba(0,0,0,.4);
}
.ai-mockup-header {
  padding: 12px 16px;
  background: rgba(139,92,246,.12);
  border-bottom: 1px solid rgba(139,92,246,.2);
  display: flex; align-items: center; gap: 8px;
  font-size: 12px; font-weight: 700; color: #C4B5FD;
}
.ai-mockup-header .ai-dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: #8B5CF6;
  box-shadow: 0 0 8px rgba(139,92,246,.8);
  animation: pulse 1.8s ease-in-out infinite;
}
.ai-chat-body { padding: 16px; display: flex; flex-direction: column; gap: 12px; }
.ai-msg {
  display: flex; gap: 8px; align-items: flex-start;
}
.ai-msg.user { flex-direction: row-reverse; }
.ai-avatar {
  width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
  background: rgba(139,92,246,.2);
  border: 1px solid rgba(139,92,246,.3);
  display: flex; align-items: center; justify-content: center;
  font-size: 12px;
}
.ai-msg.user .ai-avatar { background: rgba(53,232,213,.15); border-color: rgba(53,232,213,.3); }
.ai-bubble {
  max-width: 82%;
  padding: 9px 13px; border-radius: 12px;
  font-size: 12px; line-height: 1.5; color: rgba(255,255,255,.85);
  background: rgba(139,92,246,.12);
  border: 1px solid rgba(139,92,246,.18);
}
.ai-msg.user .ai-bubble {
  background: rgba(53,232,213,.08);
  border-color: rgba(53,232,213,.2);
}
.ai-bubble strong { color: #C4B5FD; }
.ai-bubble .teal  { color: var(--teal); font-weight: 600; }
.ai-typing {
  display: flex; align-items: center; gap: 6px;
  padding: 9px 13px; border-radius: 12px;
  background: rgba(139,92,246,.08);
  border: 1px solid rgba(139,92,246,.15);
  width: fit-content;
}
.typing-dot {
  width: 5px; height: 5px; border-radius: 50%;
  background: rgba(139,92,246,.6);
  animation: typingBounce .8s ease-in-out infinite;
}
.typing-dot:nth-child(2) { animation-delay: .15s; }
.typing-dot:nth-child(3) { animation-delay: .30s; }
@keyframes typingBounce {
  0%,100% { transform: translateY(0); opacity: .4; }
  50%      { transform: translateY(-4px); opacity: 1; }
}
.ai-stats-row {
  display: grid; grid-template-columns: repeat(3,1fr);
  gap: 8px; padding: 0 16px 16px;
}
.ai-stat-chip {
  background: rgba(139,92,246,.08);
  border: 1px solid rgba(139,92,246,.15);
  border-radius: 9px; padding: 9px 10px; text-align: center;
}
.ai-stat-chip .val { font-size: 15px; font-weight: 800; color: #C4B5FD; font-family: var(--mono); }
.ai-stat-chip .lbl { font-size: 10px; color: rgba(255,255,255,.35); margin-top: 2px; }

@media (max-width: 768px) {
  .ai-hero-card { grid-template-columns: 1fr; gap: 32px; padding: 36px 24px; }
}

/* ══════════════════════════════════════════════════════
   Features Grid
══════════════════════════════════════════════════════ */
.features-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 20px;
}
.feature-card {
  background: rgba(255,255,255,.03);
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 16px;
  padding: 28px 24px;
  transition: all .25s;
  cursor: default;
}
.feature-card:hover {
  border-color: rgba(53,232,213,.25);
  background: rgba(53,232,213,.04);
  transform: translateY(-3px);
  box-shadow: 0 16px 40px rgba(0,0,0,.25);
}
.feature-icon {
  font-size: 32px; margin-bottom: 16px; display: block;
}
.feature-card h3 {
  font-size: 16px; font-weight: 700;
  margin-bottom: 10px;
}
.feature-card p {
  font-size: 13.5px; color: rgba(255,255,255,.5); line-height: 1.6;
}

/* ══════════════════════════════════════════════════════
   How it Works
══════════════════════════════════════════════════════ */
.how-it-works { background: rgba(255,255,255,.015); }
.steps-row {
  display: grid; grid-template-columns: repeat(3, 1fr);
  gap: 24px; position: relative;
}
.steps-row::before {
  content: '';
  position: absolute; top: 42px; left: 16.5%; right: 16.5%;
  height: 2px;
  background: linear-gradient(90deg, transparent, var(--teal), transparent);
  opacity: .3;
}
.step-card { text-align: center; padding: 32px 20px; }
.step-num-wrap {
  width: 56px; height: 56px; border-radius: 50%;
  background: var(--teal-l);
  border: 2px solid rgba(53,232,213,.25);
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; font-weight: 800; color: var(--teal);
  margin: 0 auto 20px;
  font-family: var(--mono);
  box-shadow: 0 0 24px rgba(53,232,213,.15);
}
.step-card h3 { font-size: 17px; font-weight: 700; margin-bottom: 10px; }
.step-card p  { font-size: 13.5px; color: rgba(255,255,255,.5); line-height: 1.6; }

/* ══════════════════════════════════════════════════════
   Pricing
══════════════════════════════════════════════════════ */
.pricing-grid {
  display: grid; grid-template-columns: repeat(2, 1fr);
  gap: 24px; max-width: 740px; margin: 0 auto;
}
.pricing-card {
  background: rgba(255,255,255,.03);
  border: 1.5px solid rgba(255,255,255,.09);
  border-radius: 20px; padding: 36px 30px;
  position: relative; transition: all .25s;
}
.pricing-card.featured {
  border-color: rgba(53,232,213,.3);
  background: rgba(53,232,213,.04);
  box-shadow: 0 0 0 1px rgba(53,232,213,.12), 0 24px 60px rgba(0,0,0,.25);
}
.pricing-card:hover { transform: translateY(-3px); }
.pricing-badge {
  position: absolute; top: -12px; left: 50%; transform: translateX(-50%);
  padding: 4px 16px; border-radius: 100px;
  background: linear-gradient(135deg, var(--teal), var(--teal-d));
  color: var(--navy-d); font-size: 11px; font-weight: 700; white-space: nowrap;
  letter-spacing: .04em;
}
.pricing-name {
  font-size: 13px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
  color: rgba(255,255,255,.45); margin-bottom: 12px;
}
.pricing-price {
  font-size: 40px; font-weight: 800;
  color: var(--white); margin-bottom: 6px;
  font-family: var(--mono); line-height: 1;
}
.pricing-price sup { font-size: 18px; vertical-align: top; margin-top: 8px; }
.pricing-price .per { font-size: 14px; font-weight: 500; color: rgba(255,255,255,.4); font-family: var(--font); }
.pricing-desc {
  font-size: 13px; color: rgba(255,255,255,.45);
  margin-bottom: 24px; line-height: 1.55;
}
.pricing-divider {
  height: 1px; background: rgba(255,255,255,.07); margin-bottom: 20px;
}
.pricing-features { list-style: none; display: flex; flex-direction: column; gap: 11px; margin-bottom: 28px; }
.pricing-features li {
  font-size: 13.5px; color: rgba(255,255,255,.7);
  display: flex; align-items: flex-start; gap: 10px;
}
.pricing-features li::before {
  content: '✓';
  color: var(--teal); font-weight: 800; flex-shrink: 0;
}
.btn-pricing {
  display: block; width: 100%; text-align: center;
  padding: 13px;
  font-family: var(--font); font-size: 14px; font-weight: 700;
  border-radius: 11px; cursor: pointer; transition: all .2s;
  border: none;
}
.btn-pricing-primary {
  color: var(--navy-d);
  background: linear-gradient(135deg, var(--teal), var(--teal-d));
  box-shadow: 0 4px 18px rgba(53,232,213,.3);
}
.btn-pricing-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(53,232,213,.45); }
.btn-pricing-outline {
  color: rgba(255,255,255,.75);
  background: rgba(255,255,255,.05);
  border: 1.5px solid rgba(255,255,255,.15) !important;
}
.btn-pricing-outline:hover { color: var(--white); background: rgba(255,255,255,.1); border-color: rgba(255,255,255,.3) !important; }

/* ══════════════════════════════════════════════════════
   Testimonials
══════════════════════════════════════════════════════ */
.testimonials-grid {
  display: grid; grid-template-columns: repeat(3, 1fr);
  gap: 20px;
}
.testi-card {
  background: rgba(255,255,255,.03);
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 16px; padding: 26px 22px;
  transition: all .2s;
}
.testi-card:hover {
  border-color: rgba(53,232,213,.15);
  transform: translateY(-2px);
}
.testi-stars { color: #FCD34D; font-size: 14px; margin-bottom: 14px; letter-spacing: 3px; }
.testi-text  {
  font-size: 14px; line-height: 1.7;
  color: rgba(255,255,255,.65);
  margin-bottom: 20px; font-style: italic;
}
.testi-author { display: flex; align-items: center; gap: 12px; }
.testi-avatar {
  width: 40px; height: 40px; border-radius: 50%;
  background: linear-gradient(135deg, var(--teal-l), rgba(53,232,213,.25));
  border: 2px solid rgba(53,232,213,.2);
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; flex-shrink: 0;
}
.testi-name  { font-size: 13px; font-weight: 700; }
.testi-role  { font-size: 11px; color: rgba(255,255,255,.4); margin-top: 2px; }

/* ══════════════════════════════════════════════════════
   CTA Banner
══════════════════════════════════════════════════════ */
.cta-banner {
  position: relative; z-index: 1;
  padding: 80px 24px;
}
.cta-inner {
  max-width: 760px; margin: 0 auto;
  background: linear-gradient(135deg, rgba(53,232,213,.12) 0%, rgba(27,45,90,.4) 100%);
  border: 1.5px solid rgba(53,232,213,.2);
  border-radius: 24px;
  padding: 60px 40px; text-align: center;
  position: relative; overflow: hidden;
}
.cta-inner::before {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(ellipse at 50% 0%, rgba(53,232,213,.1), transparent 60%);
  pointer-events: none;
}
.cta-inner h2 {
  font-size: clamp(24px, 4vw, 38px);
  font-weight: 800; margin-bottom: 14px;
  letter-spacing: -.02em;
}
.cta-inner p {
  font-size: 16px; color: rgba(255,255,255,.55);
  margin-bottom: 36px; line-height: 1.6;
}
.cta-btns {
  display: flex; justify-content: center; gap: 14px; flex-wrap: wrap;
}

/* ══════════════════════════════════════════════════════
   Footer
══════════════════════════════════════════════════════ */
.footer {
  position: relative; z-index: 1;
  background: var(--navy-c);
  border-top: 1px solid rgba(255,255,255,.06);
  padding: 50px 24px 30px;
}
.footer-grid {
  max-width: 1100px; margin: 0 auto;
  display: grid; grid-template-columns: 2fr 1fr 1fr 1fr;
  gap: 40px; margin-bottom: 40px;
}
.footer-logo {
  display: flex; align-items: center; gap: 10px;
  font-size: 18px; font-weight: 800; margin-bottom: 14px;
}
.footer-logo .lb {
  width: 32px; height: 32px; border-radius: 8px;
  background: linear-gradient(135deg, var(--teal), var(--teal-d));
  display: flex; align-items: center; justify-content: center;
  font-size: 16px;
}
.footer-tagline { font-size: 13.5px; color: rgba(255,255,255,.4); line-height: 1.65; max-width: 220px; }
.footer-col-title {
  font-size: 11px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
  color: rgba(255,255,255,.3); margin-bottom: 16px;
}
.footer-links { list-style: none; display: flex; flex-direction: column; gap: 10px; }
.footer-links a { font-size: 13.5px; color: rgba(255,255,255,.5); transition: color .15s; }
.footer-links a:hover { color: var(--teal); }
.footer-bottom {
  max-width: 1100px; margin: 0 auto;
  padding-top: 24px;
  border-top: 1px solid rgba(255,255,255,.06);
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 12px;
  font-size: 12px; color: rgba(255,255,255,.3);
}
.footer-bottom a { color: rgba(255,255,255,.4); transition: color .15s; }
.footer-bottom a:hover { color: var(--teal); }

/* ══════════════════════════════════════════════════════
   Responsive
══════════════════════════════════════════════════════ */
@media (max-width: 900px) {
  .features-grid      { grid-template-columns: repeat(2, 1fr); }
  .testimonials-grid  { grid-template-columns: repeat(2, 1fr); }
  .footer-grid        { grid-template-columns: 1fr 1fr; }
  .mockup-body        { grid-template-columns: 1fr; }
  .mockup-sidebar     { display: none; }
  .mockup-stats       { grid-template-columns: repeat(3, 1fr); }
  .steps-row::before  { display: none; }
}
@media (max-width: 640px) {
  .nav-links, .btn-login-nav, .btn-cta-nav { display: none; }
  .nav-toggle    { display: block; }
  .hero          { padding: 120px 20px 60px; }
  .features-grid { grid-template-columns: 1fr; }
  .steps-row     { grid-template-columns: 1fr; }
  .pricing-grid  { grid-template-columns: 1fr; }
  .testimonials-grid { grid-template-columns: 1fr; }
  .footer-grid   { grid-template-columns: 1fr; }
  .stats-inner   { grid-template-columns: 1fr; }
  .stat-item     { border-right: none; border-bottom: 1px solid rgba(53,232,213,.1); }
  .stat-item:last-child { border-bottom: none; }
  .cta-inner     { padding: 40px 24px; }
  .section       { padding: 60px 20px; }
  .mockup-stats  { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="bg-grid"></div>

<!-- ── NAVBAR ──────────────────────────────────────── -->
<nav class="navbar" id="navbar">
  <a href="/ERP/harpy/landing.php" class="nav-logo">
    <img src="/ERP/harpy/assets/logo.png" alt="LAMASY" style="height:36px; vertical-align:middle; margin-right:8px;">
    LAMASY
  </a>
  <ul class="nav-links">
    <li><a href="#ai" style="color:#C4B5FD;font-weight:700;">✦ AI</a></li>
    <li><a href="#fitur">Fitur</a></li>
    <li><a href="#cara-kerja">Cara Kerja</a></li>
    <li><a href="#harga">Harga</a></li>
    <li><a href="#kontak">Kontak</a></li>
  </ul>
  <div class="nav-actions">
    <a href="/ERP/harpy/login.php" class="btn-login-nav">Masuk</a>
    <a href="/ERP/harpy/register.php" class="btn-cta-nav">Mulai Gratis</a>
  </div>
  <button class="nav-toggle" onclick="toggleMobileMenu()" id="navToggle" aria-label="Menu">&#9776;</button>
</nav>

<!-- Mobile Menu -->
<div class="nav-mobile-menu" id="mobileMenu">
  <a href="#fitur" onclick="closeMobileMenu()">Fitur</a>
  <a href="#cara-kerja" onclick="closeMobileMenu()">Cara Kerja</a>
  <a href="#harga" onclick="closeMobileMenu()">Harga</a>
  <a href="#kontak" onclick="closeMobileMenu()">Kontak</a>
  <div class="nav-mobile-divider"></div>
  <a href="/ERP/harpy/login.php">Masuk ke Akun</a>
  <a href="/ERP/harpy/register.php" style="color:var(--teal);font-weight:700;">&#128640; Mulai Gratis</a>
</div>

<!-- ── HERO ────────────────────────────────────────── -->
<section class="hero">
  <div class="hero-orb-wrap">
    <div class="orb orb-teal" style="top:-100px;right:-80px;"></div>
    <div class="orb orb-blue" style="bottom:-100px;left:-80px;"></div>
  </div>

  <div class="hero-badge">
    <span class="dot"></span>
    Platform Laundry #1 di Indonesia
  </div>

  <h1>ERP Laundry Modern dengan <span class="accent">Kecerdasan AI</span> Terintegrasi</h1>
  <p class="hero-sub">Kelola order, karyawan, dan keuangan — sekarang diperkuat AI yang menganalisis data bisnis Anda otomatis setiap hari. Setup dalam hitungan menit.</p>

  <div class="hero-btns">
    <a href="/ERP/harpy/register.php" class="btn-primary">&#128640; Coba Gratis 30 Hari</a>
    <a href="#fitur" class="btn-secondary">&#9654; Lihat Demo</a>
  </div>

  <!-- Dashboard Mockup -->
  <div class="hero-mockup">
    <div class="mockup-topbar">
      <div class="mockup-dot"></div>
      <div class="mockup-dot"></div>
      <div class="mockup-dot"></div>
      <div class="mockup-url">harpy.id/ERP/harpy/</div>
    </div>
    <div class="mockup-body">
      <div class="mockup-sidebar">
        <div class="mockup-sidebar-logo">
          <div class="mb"></div>
          <span>LAMASY</span>
        </div>
        <div class="mockup-nav-item active"><span class="ni">📊</span> Dashboard</div>
        <div class="mockup-nav-item"><span class="ni">🛒</span> Order</div>
        <div class="mockup-nav-item"><span class="ni">👥</span> Karyawan</div>
        <div class="mockup-nav-item"><span class="ni">💰</span> Keuangan</div>
        <div class="mockup-nav-item"><span class="ni">📦</span> Layanan</div>
        <div class="mockup-nav-item"><span class="ni">🎟️</span> Promo</div>
        <div class="mockup-nav-item"><span class="ni">⚙️</span> Pengaturan</div>
      </div>
      <div class="mockup-content">
        <div class="mockup-content-title">Dashboard Hari Ini</div>
        <div class="mockup-stats">
          <div class="mockup-stat-card">
            <div class="ms-label">Pendapatan</div>
            <div class="ms-val green">Rp 2,4 Jt</div>
          </div>
          <div class="mockup-stat-card">
            <div class="ms-label">Order Baru</div>
            <div class="ms-val blue">47</div>
          </div>
          <div class="mockup-stat-card">
            <div class="ms-label">Siap Ambil</div>
            <div class="ms-val orange">12</div>
          </div>
        </div>
        <div class="mockup-orders">
          <div class="mockup-order-row header">
            <span>Nama Pelanggan</span>
            <span>Total</span>
            <span>Status</span>
          </div>
          <div class="mockup-order-row">
            <span>Budi Santoso</span>
            <span>Rp 48k</span>
            <span class="status-badge done">Selesai</span>
          </div>
          <div class="mockup-order-row">
            <span>Sari Dewi</span>
            <span>Rp 72k</span>
            <span class="status-badge proc">Proses</span>
          </div>
          <div class="mockup-order-row">
            <span>Ahmad Fauzi</span>
            <span>Rp 35k</span>
            <span class="status-badge ready">Siap</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ── STATS BAR ────────────────────────────────────── -->
<div class="stats-bar">
  <div class="stats-inner">
    <div class="stat-item">
      <div class="stat-num">500+</div>
      <div class="stat-label">Outlet Aktif</div>
    </div>
    <div class="stat-item">
      <div class="stat-num">99.9%</div>
      <div class="stat-label">Uptime Terjamin</div>
    </div>
    <div class="stat-item">
      <div class="stat-num">3 Jam</div>
      <div class="stat-label">Hemat per Hari</div>
    </div>
    <div class="stat-item">
      <div class="stat-num" style="color:#C4B5FD;">AI</div>
      <div class="stat-label">Briefing Harian Otomatis</div>
    </div>
  </div>
</div>

<!-- ── AI SECTION ─────────────────────────────────── -->
<div class="ai-section" id="ai">
  <div class="ai-inner">
    <div class="ai-badge-row">
      <div class="ai-badge"><span class="pulse"></span> Powered by AI</div>
    </div>
    <div class="ai-hero-card">
      <!-- Left: text -->
      <div class="ai-hero-text">
        <h2>Bukan Sekadar Software —<br><em>LAMASY Punya Otak AI</em></h2>
        <p>Teknologi kecerdasan buatan terintegrasi langsung di dalam sistem. LAMASY menganalisis data bisnis Anda secara otomatis dan memberikan insight yang actionable — setiap hari.</p>
        <div class="ai-features-list">
          <div class="ai-feat-item">
            <div class="ai-feat-icon">📋</div>
            <div class="ai-feat-text">
              <strong>Briefing Harian Otomatis</strong>
              <span>Setiap pagi, AI merangkum performa outlet Anda — pendapatan, order pending, karyawan hadir, dan rekomendasi prioritas hari ini.</span>
            </div>
          </div>
          <div class="ai-feat-item">
            <div class="ai-feat-icon">📈</div>
            <div class="ai-feat-text">
              <strong>Analisis Tren & Prediksi</strong>
              <span>AI mendeteksi pola pendapatan, jam puncak order, dan memperingatkan potensi penurunan sebelum terjadi.</span>
            </div>
          </div>
          <div class="ai-feat-item">
            <div class="ai-feat-icon">💡</div>
            <div class="ai-feat-text">
              <strong>Rekomendasi Bisnis Cerdas</strong>
              <span>Saran konkret berbasis data: kapan waktu terbaik promosi, layanan mana yang paling profitable, dan lebih banyak lagi.</span>
            </div>
          </div>
          <div class="ai-feat-item">
            <div class="ai-feat-icon">🔔</div>
            <div class="ai-feat-text">
              <strong>Notifikasi WA dengan AI</strong>
              <span>Pesan WhatsApp ke pelanggan dibuat otomatis oleh AI — personal, tepat waktu, dan natural. Bukan template kaku.</span>
            </div>
          </div>
        </div>
      </div>
      <!-- Right: AI chat mockup -->
      <div>
        <div class="ai-mockup">
          <div class="ai-mockup-header">
            <div class="ai-dot"></div>
            LAMASY AI Assistant
          </div>
          <div class="ai-chat-body">
            <div class="ai-msg user">
              <div class="ai-avatar">👤</div>
              <div class="ai-bubble">Gimana performa outlet hari ini?</div>
            </div>
            <div class="ai-msg">
              <div class="ai-avatar">🤖</div>
              <div class="ai-bubble">
                Halo! Berikut ringkasan hari ini (Kamis, 15 Mei):<br><br>
                <strong>📦 Order masuk:</strong> <span class="teal">47 order</span> (+12% vs kemarin)<br>
                <strong>💰 Pendapatan:</strong> <span class="teal">Rp 2,4 Juta</span><br>
                <strong>⚠️ Perhatian:</strong> 8 order sudah 2 hari belum diambil — perlu WA reminder.<br><br>
                <strong>💡 Rekomendasi:</strong> Aktifkan promo "Cuci Kilat" di jam 14.00–17.00, biasanya sepi tapi demand ada.
              </div>
            </div>
            <div class="ai-msg user">
              <div class="ai-avatar">👤</div>
              <div class="ai-bubble">Kirim WA reminder ke yang belum ambil dong</div>
            </div>
            <div class="ai-msg">
              <div class="ai-avatar">🤖</div>
              <div class="ai-typing">
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
              </div>
            </div>
          </div>
          <div class="ai-stats-row">
            <div class="ai-stat-chip">
              <div class="val">47</div>
              <div class="lbl">Order Hari Ini</div>
            </div>
            <div class="ai-stat-chip">
              <div class="val">2.4 Jt</div>
              <div class="lbl">Pendapatan</div>
            </div>
            <div class="ai-stat-chip">
              <div class="val">+12%</div>
              <div class="lbl">vs Kemarin</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── FITUR ────────────────────────────────────────── -->
<section class="section" id="fitur">
  <div class="section-header">
    <div class="section-tag">Fitur Unggulan</div>
    <h2>Semua yang Anda Butuhkan dalam Satu Platform</h2>
    <p>Dari kasir hingga laporan keuangan, dari absensi karyawan hingga notifikasi pelanggan — semua ada di LAMASY.</p>
  </div>
  <div class="features-grid">
    <div class="feature-card">
      <span class="feature-icon">🛒</span>
      <h3>POS & Order Management</h3>
      <p>Kasir digital yang cepat dan mudah. Terima order, cetak nota, track status cucian secara real-time dari satu layar.</p>
    </div>
    <div class="feature-card">
      <span class="feature-icon">📊</span>
      <h3>Laporan Keuangan Real-time</h3>
      <p>Dashboard keuangan lengkap dengan grafik pendapatan harian, mingguan, dan bulanan. Ekspor laporan kapanpun.</p>
    </div>
    <div class="feature-card">
      <span class="feature-icon">👥</span>
      <h3>Manajemen Karyawan & Absensi</h3>
      <p>Atur jadwal kerja, pantau absensi, hitung gaji otomatis, dan kelola performa karyawan dengan mudah.</p>
    </div>
    <div class="feature-card">
      <span class="feature-icon">💰</span>
      <h3>Kas & Pembukuan Otomatis</h3>
      <p>Catat pemasukan dan pengeluaran otomatis. Buku kas digital yang rapi, akurat, dan bisa diakses kapanpun.</p>
    </div>
    <div class="feature-card">
      <span class="feature-icon">🎟️</span>
      <h3>Promo & Voucher</h3>
      <p>Buat promo diskon, voucher cashback, dan program loyalitas pelanggan untuk meningkatkan repeat order.</p>
    </div>
    <div class="feature-card">
      <span class="feature-icon">🔔</span>
      <h3>Notifikasi WhatsApp</h3>
      <p>Kirim notifikasi WA otomatis ke pelanggan saat cucian siap. Teks dibuat AI — personal dan natural, bukan template kaku.</p>
    </div>
  </div>
</section>

<!-- ── HOW IT WORKS ─────────────────────────────────── -->
<div class="how-it-works">
  <section class="section" id="cara-kerja">
    <div class="section-header">
      <div class="section-tag">Cara Kerja</div>
      <h2>Mulai dalam 3 Langkah Mudah</h2>
      <p>Tidak perlu keahlian teknis. LAMASY dirancang agar siapapun bisa langsung pakai.</p>
    </div>
    <div class="steps-row">
      <div class="step-card">
        <div class="step-num-wrap">1</div>
        <h3>Daftar Akun</h3>
        <p>Isi form registrasi sederhana. Akun trial 30 hari langsung aktif. Tidak perlu kartu kredit, tidak ada komitmen.</p>
      </div>
      <div class="step-card">
        <div class="step-num-wrap">2</div>
        <h3>Setup Outlet</h3>
        <p>Konfigurasikan layanan laundry, harga, karyawan, dan pengaturan outlet Anda dipandu wizard setup yang mudah.</p>
      </div>
      <div class="step-card">
        <div class="step-num-wrap">3</div>
        <h3>Mulai Operasional</h3>
        <p>Terima order pertama, lihat laporan real-time, dan kirim notifikasi WA ke pelanggan. Bisnis makin rapi!</p>
      </div>
    </div>
  </section>
</div>

<!-- ── HARGA ────────────────────────────────────────── -->
<section class="section" id="harga">
  <div class="section-header">
    <div class="section-tag">Harga</div>
    <h2>Transparan, Tanpa Biaya Tersembunyi</h2>
    <p>Mulai gratis, upgrade saat bisnis Anda tumbuh. Tidak ada kontrak jangka panjang.</p>
  </div>
  <div class="pricing-grid">
    <!-- Trial Card -->
    <div class="pricing-card">
      <div class="pricing-name">Trial</div>
      <div class="pricing-price">Gratis<div class="per">30 hari pertama</div></div>
      <div class="pricing-desc">Coba semua fitur LAMASY tanpa risiko. Tidak perlu kartu kredit.</div>
      <div class="pricing-divider"></div>
      <ul class="pricing-features">
        <li>Semua fitur POS & order</li>
        <li>Laporan keuangan lengkap</li>
        <li>50.000 coin notifikasi WA</li>
        <li>1 outlet, unlimited order</li>
        <li>Manajemen karyawan</li>
        <li style="color:#C4B5FD;">✦ AI Briefing Harian</li>
        <li style="color:#C4B5FD;">✦ AI Analisis Tren</li>
        <li>Support via WhatsApp</li>
      </ul>
      <a href="/ERP/harpy/register.php" class="btn-pricing btn-pricing-outline">Mulai Trial Gratis</a>
    </div>
    <!-- Professional Card -->
    <div class="pricing-card featured">
      <div class="pricing-badge">&#11088; Paling Populer</div>
      <div class="pricing-name">Professional</div>
      <div class="pricing-price"><sup>Rp</sup>300rb<div class="per">setup fee sekali bayar</div></div>
      <div class="pricing-desc">Setup fee untuk onboarding & konfigurasi awal. Setelah itu, hanya topup coin sesuai kebutuhan.</div>
      <div class="pricing-divider"></div>
      <ul class="pricing-features">
        <li>Semua fitur trial +</li>
        <li>Multi outlet support</li>
        <li>100.000 coin topup awal</li>
        <li style="color:#C4B5FD;">✦ AI Briefing & Analisis Tren</li>
        <li style="color:#C4B5FD;">✦ AI Rekomendasi Bisnis</li>
        <li style="color:#C4B5FD;">✦ WA Notifikasi berbasis AI</li>
        <li>Priority support 24/7</li>
        <li>Training & onboarding</li>
      </ul>
      <a href="/ERP/harpy/register.php" class="btn-pricing btn-pricing-primary">Daftar Sekarang</a>
    </div>
  </div>
</section>

<!-- ── TESTIMONIALS ─────────────────────────────────── -->
<div style="background:rgba(255,255,255,.015);position:relative;z-index:1;">
  <section class="section">
    <div class="section-header">
      <div class="section-tag">Testimoni</div>
      <h2>Dipercaya Ratusan Pemilik Laundry</h2>
      <p>Lihat apa kata mereka yang sudah merasakan manfaat LAMASY untuk bisnis mereka.</p>
    </div>
    <div class="testimonials-grid">
      <div class="testi-card">
        <div class="testi-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
        <div class="testi-text">"Sejak pakai LAMASY, pelaporan keuangan outlet saya jauh lebih rapi. Dulu harus rekap manual di Excel, sekarang tinggal buka dashboard sudah keliatan semua. Hemat banget!"</div>
        <div class="testi-author">
          <div class="testi-avatar">&#x1F464;</div>
          <div>
            <div class="testi-name">Ibu Ratna Sari</div>
            <div class="testi-role">Pemilik Fresh Laundry, Semarang</div>
          </div>
        </div>
      </div>
      <div class="testi-card">
        <div class="testi-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
        <div class="testi-text">"Fitur notifikasi WA-nya luar biasa! Pelanggan langsung tahu kalau cuciannya sudah selesai tanpa perlu saya telepon satu-satu. Komplain berkurang drastis."</div>
        <div class="testi-author">
          <div class="testi-avatar">&#x1F464;</div>
          <div>
            <div class="testi-name">Pak Hendra Wijaya</div>
            <div class="testi-role">Owner Bersih Laundry, Surabaya</div>
          </div>
        </div>
      </div>
      <div class="testi-card">
        <div class="testi-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
        <div class="testi-text">"Saya punya 3 outlet dan sebelumnya kesulitan monitor semua dari satu tempat. Dengan LAMASY, semua data terpusat. Setup-nya juga mudah, tim CS sangat responsif."</div>
        <div class="testi-author">
          <div class="testi-avatar">&#x1F464;</div>
          <div>
            <div class="testi-name">Budi Prasetiyo</div>
            <div class="testi-role">Direktur Bersinar Laundry Group, Jakarta</div>
          </div>
        </div>
      </div>
      <div class="testi-card" style="border-color:rgba(139,92,246,.25);background:rgba(139,92,246,.05);">
        <div class="testi-stars" style="color:#C4B5FD;">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
        <div class="testi-text">"Fitur AI Briefing-nya yang paling bikin saya takjub. Setiap pagi ada ringkasan otomatis — berapa order kemarin, mana yang belum dibayar, karyawan siapa yang sering telat. Saya jadi lebih cepat ambil keputusan tanpa harus buka banyak laporan."</div>
        <div class="testi-author">
          <div class="testi-avatar" style="background:rgba(139,92,246,.2);border-color:rgba(139,92,246,.3);">&#x1F464;</div>
          <div>
            <div class="testi-name">Dewi Kusuma</div>
            <div class="testi-role" style="color:#C4B5FD;">Owner Kilat Bersih Laundry, Bandung — <em>pengguna fitur AI</em></div>
          </div>
        </div>
      </div>
    </div>
  </section>
</div>

<!-- ── CTA BANNER ───────────────────────────────────── -->
<div class="cta-banner">
  <div class="cta-inner">
    <h2>Siap Digitalisasi Laundry Anda?</h2>
    <p>Bergabung dengan 500+ outlet laundry yang sudah lebih rapi bersama LAMASY. Daftar gratis, tidak perlu kartu kredit.</p>
    <div class="cta-btns">
      <a href="/ERP/harpy/register.php" class="btn-primary">&#128640; Mulai Gratis 30 Hari</a>
      <a href="https://wa.me/6281234567890?text=Halo+saya+ingin+tahu+lebih+lanjut+tentang+LAMASY" target="_blank" class="btn-secondary">&#128172; Chat WhatsApp</a>
    </div>
  </div>
</div>

<!-- ── FOOTER ───────────────────────────────────────── -->
<footer class="footer" id="kontak">
  <div class="footer-grid">
    <div>
      <div class="footer-logo">
        <img src="/ERP/harpy/assets/logo.png" alt="LAMASY" style="height:40px; vertical-align:middle; margin-right:8px;">
        LAMASY <span style="font-size:13px;font-weight:500;color:rgba(255,255,255,.45);">by Harpy</span>
      </div>
      <div class="footer-tagline">Platform manajemen laundry modern untuk bisnis yang lebih rapi.</div>
    </div>
    <div>
      <div class="footer-col-title">Produk</div>
      <ul class="footer-links">
        <li><a href="#fitur">Fitur</a></li>
        <li><a href="#harga">Harga</a></li>
        <li><a href="#cara-kerja">Cara Kerja</a></li>
        <li><a href="/ERP/harpy/register.php">Daftar</a></li>
      </ul>
    </div>
    <div>
      <div class="footer-col-title">Akun</div>
      <ul class="footer-links">
        <li><a href="/ERP/harpy/login.php">Login</a></li>
        <li><a href="/ERP/harpy/register.php">Daftar Gratis</a></li>
        <li><a href="#">Reset Password</a></li>
      </ul>
    </div>
    <div>
      <div class="footer-col-title">Kontak</div>
      <ul class="footer-links">
        <li><a href="https://wa.me/6281234567890" target="_blank">&#128172; WhatsApp</a></li>
        <li><a href="mailto:halo@harpy.id">&#9993; halo@harpy.id</a></li>
        <li><a href="#">&#127968; Jakarta, Indonesia</a></li>
      </ul>
    </div>
  </div>
  <div class="footer-bottom">
    <span>&copy; <?= date('Y') ?> PT Harpy Sinergi Mandiri. All rights reserved.</span>
    <div style="display:flex;gap:20px;">
      <a href="#">Privasi</a>
      <a href="#">Syarat & Ketentuan</a>
      <!-- Super admin link - kecil, tidak mencolok -->
      <a href="/ERP/harpy/superadmin/login.php" style="opacity:.4;font-size:11px;">Admin</a>
    </div>
  </div>
</footer>

<script>
// ── Mobile nav toggle ──────────────────────────────
function toggleMobileMenu() {
  const menu = document.getElementById('mobileMenu');
  const toggle = document.getElementById('navToggle');
  const isOpen = menu.classList.toggle('open');
  toggle.innerHTML = isOpen ? '&#x2715;' : '&#9776;';
}
function closeMobileMenu() {
  document.getElementById('mobileMenu').classList.remove('open');
  document.getElementById('navToggle').innerHTML = '&#9776;';
}

// ── Navbar scroll effect ───────────────────────────
window.addEventListener('scroll', function() {
  const nav = document.getElementById('navbar');
  if (window.scrollY > 40) {
    nav.style.background = 'rgba(11,22,48,.97)';
    nav.style.boxShadow = '0 2px 30px rgba(0,0,0,.4)';
  } else {
    nav.style.background = 'rgba(15,28,58,.85)';
    nav.style.boxShadow = 'none';
  }
});

// ── Scroll-reveal animation ────────────────────────
const observer = new IntersectionObserver((entries) => {
  entries.forEach(e => {
    if (e.isIntersecting) {
      e.target.style.opacity = '1';
      e.target.style.transform = 'translateY(0)';
    }
  });
}, { threshold: 0.1 });

document.querySelectorAll('.feature-card, .step-card, .pricing-card, .testi-card').forEach(el => {
  el.style.opacity = '0';
  el.style.transform = 'translateY(24px)';
  el.style.transition = 'opacity .5s ease, transform .5s ease';
  observer.observe(el);
});
</script>
</body>
</html>
