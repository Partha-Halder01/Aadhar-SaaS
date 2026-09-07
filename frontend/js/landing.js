/**
 * Utkal Print Portal - Dynamic Landing Page Hydration Client
 * Seamlessly hydrates http://127.0.0.1:3000/ with live Admin CMS settings
 */

document.addEventListener('DOMContentLoaded', async () => {
  try {
    // Add cache busting timestamp to ensure fresh content after admin saves
    const res = await API.request('/landing-page?t=' + Date.now(), { useCache: false });
    if (!res || !res.data) return;

    const data = res.data;
    hydrateLandingPage(data);
  } catch (err) {
    console.warn('Using default fallback landing page content:', err.message);
  }
});

function hydrateLandingPage(data) {
  // 1. Section Visibility Toggles
  if (data.visibility) {
    const v = data.visibility;
    toggleSection('heroSection', v.hero !== false);
    toggleSection('services', v.services !== false);
    toggleSection('pricing', v.pricing !== false);
    toggleSection('how-it-works', v.workflow !== false);
    toggleSection('testimonialsSection', v.testimonials !== false);
    toggleSection('faq', v.faq !== false);
    toggleSection('ctaSection', v.cta !== false);

    // Announcement bar toggle
    const annBar = document.querySelector('.top-announcement-bar');
    if (annBar) {
      annBar.style.display = (v.announcement === false) ? 'none' : '';
    }

    // Showcase toggle
    const showcase = document.querySelector('.rz-showcase-wrapper');
    if (showcase) {
      showcase.style.display = (v.showcase === false) ? 'none' : '';
    }

    // Footer toggle
    const footer = document.querySelector('.footer-modern');
    if (footer) {
      footer.style.display = (v.footer === false) ? 'none' : '';
    }
  }

  // 0. Top Announcement Bar
  if (data.announcement) {
    const an = data.announcement;
    const annBar = document.querySelector('.top-announcement-bar');
    if (annBar) {
      if (an.enabled === false) annBar.style.display = 'none';
      const badge = annBar.querySelector('.announcement-badge span:last-child');
      if (badge && an.badge) badge.textContent = an.badge;
      const text = annBar.querySelector('.announcement-text');
      if (text && an.text) text.textContent = an.text;
      const link = annBar.querySelector('.announcement-link');
      if (link) {
        if (an.helpline_text) link.innerHTML = `<i class="fa-solid fa-headset"></i> ${escapeHtml(an.helpline_text)}`;
        if (an.helpline_link) link.setAttribute('href', an.helpline_link);
      }
      const safe = annBar.querySelector('.announcement-safe');
      if (safe && an.safe_text) safe.innerHTML = `<i class="fa-solid fa-shield-halved"></i> ${escapeHtml(an.safe_text)}`;
    }
  }

  // 1. Hero Section
  if (data.hero) {
    const h = data.hero;
    const heroBadge = document.getElementById('heroBadge');
    if (heroBadge && h.badge_text) {
      heroBadge.innerHTML = `<i class="fa-solid ${h.badge_icon || 'fa-bolt-lightning'}"></i> ${escapeHtml(h.badge_text)}`;
    }

    const heroTitle = document.getElementById('heroTitle');
    if (heroTitle && (h.title_highlight || h.title_rest)) {
      heroTitle.innerHTML = `${escapeHtml(h.title_rest || 'Next-Gen')} <span class="rz-hero-gradient-text">${escapeHtml(h.title_highlight || 'Digital Document & PAN')}</span> Suite`;
    }

    setText('heroSubtitle', h.subtitle);

    const ctaPrimary = document.getElementById('heroCtaPrimary');
    if (ctaPrimary && h.cta_primary_text) {
      const span = ctaPrimary.querySelector('span');
      if (span) span.textContent = h.cta_primary_text;
      if (h.cta_primary_link) ctaPrimary.setAttribute('href', h.cta_primary_link);
    }

    const ctaSecondary = document.getElementById('heroCtaSecondary');
    if (ctaSecondary && h.cta_secondary_text) {
      const span = ctaSecondary.querySelector('span');
      if (span) span.textContent = h.cta_secondary_text;
      if (h.cta_secondary_link) ctaSecondary.setAttribute('href', h.cta_secondary_link);
    }

    const trustRow = document.getElementById('heroTrustRow');
    if (trustRow && Array.isArray(h.trust_items) && h.trust_items.length > 0) {
      trustRow.innerHTML = h.trust_items.map(item => `
        <div class="rz-trust-item">
          <i class="fa-solid ${item.icon || 'fa-circle-check'}"></i>
          <span>${escapeHtml(item.text)}</span>
        </div>
      `).join('');
    }
  }

  // 1.1 Showcase Widget
  if (data.showcase) {
    const sc = data.showcase;
    const chip1Title = document.querySelector('.rz-float-chip.top-right .rz-chip-title');
    if (chip1Title && sc.chip1_title) chip1Title.textContent = sc.chip1_title;
    const chip1Sub = document.querySelector('.rz-float-chip.top-right .rz-chip-sub');
    if (chip1Sub && sc.chip1_sub) chip1Sub.textContent = sc.chip1_sub;

    const chip2Title = document.querySelector('.rz-float-chip.bottom-left .rz-chip-title');
    if (chip2Title && sc.chip2_title) chip2Title.textContent = sc.chip2_title;
    const chip2Sub = document.querySelector('.rz-float-chip.bottom-left .rz-chip-sub');
    if (chip2Sub && sc.chip2_sub) chip2Sub.textContent = sc.chip2_sub;

    const appName = document.querySelector('.rz-applicant-name');
    if (appName && sc.applicant_name) appName.textContent = sc.applicant_name;
    const appMetas = document.querySelectorAll('.rz-applicant-meta');
    if (appMetas[0] && sc.applicant_meta) appMetas[0].textContent = sc.applicant_meta;
    if (appMetas[1] && sc.applicant_state) appMetas[1].textContent = sc.applicant_state;
    const appAadhaar = document.querySelector('.rz-aadhaar-number-display');
    if (appAadhaar && sc.applicant_aadhaar) appAadhaar.textContent = sc.applicant_aadhaar;
  }

  // 2. Core Services (Bento Grid)
  if (data.services) {
    const s = data.services;
    setText('servicesTag', s.tag);
    setText('servicesTitle', s.title);
    setText('servicesDesc', s.desc);

    const grid = document.getElementById('servicesGrid');
    if (grid && Array.isArray(s.items) && s.items.length > 0) {
      grid.innerHTML = s.items.map((svc, idx) => {
        const isCol8 = (idx === 0 && s.items.length > 2);
        const colClass = isCol8 ? 'rz-col-span-8' : 'rz-col-span-4';
        const color = svc.color || (idx % 3 === 0 ? 'orange' : (idx % 3 === 1 ? 'green' : 'blue'));
        const icon = svc.icon || 'fa-id-card';
        const category = svc.category || 'print';
        const features = Array.isArray(svc.features) ? svc.features : [];
        const actionText = svc.action_text || 'Start Service';
        const actionLink = svc.action_link || 'register.html';
        const badge = svc.badge || svc.price || 'Starting ₹20 / card';

        return `
        <div class="rz-bento-card ${colClass} border-${color}" data-category="${escapeAttr(category)}">
          <div class="rz-bento-badge-row">
            <div class="rz-bento-icon ${color}">
              <i class="fa-solid ${escapeAttr(icon)}"></i>
            </div>
            <span class="rz-bento-price-tag ${color}" id="svcBadge_${idx}">${escapeHtml(badge)}</span>
          </div>
          <h3 class="rz-bento-title" id="svcTitle_${idx}">${escapeHtml(svc.title)}</h3>
          <p class="rz-bento-desc" id="svcDesc_${idx}">${escapeHtml(svc.desc)}</p>
          ${features.length > 0 ? `
            <ul class="rz-bento-features-list">
              ${features.map(f => `<li><i class="fa-solid fa-circle-check"></i> ${escapeHtml(f)}</li>`).join('')}
            </ul>
          ` : ''}
          <a href="${escapeAttr(actionLink)}" class="rz-bento-action-link ${color}">
            <span>${escapeHtml(actionText)}</span>
            <i class="fa-solid fa-arrow-right"></i>
          </a>
        </div>
        `;
      }).join('');
    }
  }

  // 3. Workflow (How it Works)
  if (data.workflow) {
    const w = data.workflow;
    setText('workflowTag', w.tag);
    setText('workflowTitle', w.title);
    setText('workflowDesc', w.desc);

    const processGrid = document.querySelector('.rz-process-grid');
    if (processGrid && Array.isArray(w.steps) && w.steps.length > 0) {
      processGrid.innerHTML = w.steps.map((st, idx) => `
        <div class="rz-process-card">
          <div class="rz-process-step-num" id="stepNum_${idx}">${escapeHtml(st.step_num || String(idx + 1).padStart(2, '0'))}</div>
          <h4 class="rz-process-title" id="stepTitle_${idx}">${escapeHtml(st.title)}</h4>
          <p class="rz-process-desc" id="stepDesc_${idx}">${escapeHtml(st.desc)}</p>
        </div>
      `).join('');
    }
  }

  // 4. Rates & Pricing Table
  if (data.pricing) {
    const p = data.pricing;
    setText('pricingTag', p.tag);
    setText('pricingTitle', p.title);
    setText('pricingDesc', p.desc);

    const tableWrap = document.querySelector('.rz-pricing-card-wrap');
    if (tableWrap && Array.isArray(p.cards) && p.cards.length > 0) {
      const headerHtml = `
        <div class="rz-pricing-table-header">
          <div>Service Offering</div>
          <div>Turnaround Time</div>
          <div>Deliverable Format</div>
          <div>Standard Fee</div>
        </div>
      `;
      const rowsHtml = p.cards.map((card, idx) => `
        <div class="rz-pricing-row" ${idx % 2 === 1 ? 'style="background:#fdfcf9;"' : ''}>
          <div>
            <strong class="rz-pricing-title-main" id="pricingCardTitle_${idx}">${escapeHtml(card.title)}</strong>
            <span class="rz-pricing-sub" id="pricingCardDesc_${idx}">${escapeHtml(card.desc)}</span>
          </div>
          <div><span class="badge-status badge-completed" id="pricingCardSpeed_${idx}"><i class="fa-solid fa-bolt"></i> ${escapeHtml(card.speed)}</span></div>
          <div><span style="color:#475569; font-weight:600; font-size:0.88rem;" id="pricingCardFormat_${idx}">${escapeHtml(card.format || 'HD Vector PDF')}</span></div>
          <div><strong class="rz-pricing-rate" style="color:${card.price && card.price.includes('FREE') ? '#03a93a' : '#e04d00'};" id="pricingCardPrice_${idx}">${escapeHtml(card.price)}</strong></div>
        </div>
      `).join('');
      tableWrap.innerHTML = headerHtml + rowsHtml;
    }
  }

  // 5. Testimonials
  if (data.testimonials) {
    const t = data.testimonials;
    setText('testimonialsTag', t.tag);
    setText('testimonialsTitle', t.title);
    setText('testimonialsDesc', t.desc);

    const testGrid = document.querySelector('.testimonials-grid');
    if (testGrid && Array.isArray(t.items) && t.items.length > 0) {
      const avatarBgs = [
        'background: linear-gradient(135deg, #e0f2fe, #bae6fd); color: #0284c7;',
        'background: linear-gradient(135deg, #dcfce7, #86efac); color: #166534;',
        'background: linear-gradient(135deg, #fef3c7, #fde68a); color: #b45309;',
        'background: linear-gradient(135deg, #f3e8ff, #d8b4fe); color: #7e22ce;'
      ];
      testGrid.innerHTML = t.items.map((item, idx) => {
        const ini = item.initials || item.author.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
        const bg = avatarBgs[idx % avatarBgs.length];
        return `
        <div class="testimonial-card-modern">
          <div>
            <div class="testimonial-stars">
              ${Array.from({ length: item.stars || 5 }).map(() => '<i class="fa-solid fa-star"></i>').join('')}
            </div>
            <p class="testimonial-quote" id="reviewQuote_${idx}">
              "${escapeHtml(item.quote.replace(/^"|"$/g, ''))}"
            </p>
          </div>
          <div class="testimonial-author">
            <div class="author-avatar" style="${bg}">${escapeHtml(ini)}</div>
            <div class="author-info">
              <h5 id="reviewAuthor_${idx}">${escapeHtml(item.author)}</h5>
              <p id="reviewRole_${idx}">${escapeHtml(item.role)}</p>
            </div>
          </div>
        </div>
        `;
      }).join('');
    }
  }

  // 6. FAQ Section
  if (data.faq) {
    const f = data.faq;
    setText('faqTag', f.tag);
    setText('faqTitle', f.title);
    setText('faqDesc', f.desc);

    const faqContainer = document.getElementById('faqAccordionContainer');
    if (faqContainer && Array.isArray(f.items) && f.items.length > 0) {
      faqContainer.innerHTML = f.items.map((item, idx) => `
        <div class="rz-faq-item ${idx === 0 ? 'active' : ''}">
          <button type="button" class="rz-faq-trigger" onclick="toggleRzFaq(this)">
            <span>${escapeHtml(item.question)}</span>
            <div class="rz-faq-icon"><i class="fa-solid fa-chevron-down"></i></div>
          </button>
          <div class="rz-faq-body">
            ${escapeHtml(item.answer)}
          </div>
        </div>
      `).join('');
    }
  }

  // 7. Bottom CTA Banner
  if (data.cta) {
    const c = data.cta;
    const ctaBadge = document.getElementById('ctaBadge');
    if (ctaBadge && c.badge) {
      ctaBadge.innerHTML = `<i class="fa-solid fa-bolt"></i> ${escapeHtml(c.badge)}`;
    }
    setText('ctaTitle', c.title);
    setText('ctaSubtitle', c.subtitle);

    const ctaPrimary = document.getElementById('ctaPrimaryBtn');
    if (ctaPrimary && c.primary_text) {
      ctaPrimary.querySelector('span').textContent = c.primary_text;
      if (c.primary_link) ctaPrimary.setAttribute('href', c.primary_link);
    }

    const ctaSecondary = document.getElementById('ctaSecondaryBtn');
    if (ctaSecondary && c.secondary_text) {
      ctaSecondary.querySelector('span').textContent = c.secondary_text;
      if (c.secondary_link) ctaSecondary.setAttribute('href', c.secondary_link);
    }
  }

  // 8. Footer & Contacts
  if (data.footer) {
    const ft = data.footer;
    setText('footerSlogan', ft.slogan);
    setText('footerPhoneText', ft.phone);
    setText('footerWhatsappText', ft.whatsapp);
    setText('footerEmailText', ft.email);
    setText('footerHoursText', ft.hours);
    setText('footerCopyright', ft.copyright);

    if (ft.phone) setHref('footerPhoneLink', `tel:${ft.phone.replace(/[^0-9+]/g, '')}`);
    if (ft.whatsapp) setHref('footerWhatsappLink', `https://wa.me/${ft.whatsapp.replace(/[^0-9]/g, '')}`);
    if (ft.email) setHref('footerEmailLink', `mailto:${ft.email}`);

    if (ft.facebook_link) setHref('footerSocialFacebook', ft.facebook_link);
    if (ft.twitter_link) setHref('footerSocialTwitter', ft.twitter_link);
    if (ft.whatsapp_link) setHref('footerSocialWhatsapp', ft.whatsapp_link);
    if (ft.telegram_link) setHref('footerSocialTelegram', ft.telegram_link);

    if (ft.privacy_url) setHref('footerPrivacyLink', ft.privacy_url);
    if (ft.terms_url) setHref('footerTermsLink', ft.terms_url);
    if (ft.refund_url) setHref('footerRefundLink', ft.refund_url);
  }
}

// Helpers
function setText(id, text) {
  if (!text) return;
  const el = document.getElementById(id);
  if (el) el.textContent = text;
}

function setHref(id, href) {
  if (!href) return;
  const el = document.getElementById(id);
  if (el) el.setAttribute('href', href);
}

function toggleSection(id, isVisible) {
  const el = document.getElementById(id);
  if (el) {
    el.style.display = isVisible ? '' : 'none';
  }
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function escapeAttr(str) {
  if (!str) return '';
  return String(str).replace(/"/g, '&quot;');
}
