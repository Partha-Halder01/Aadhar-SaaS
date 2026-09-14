function enrichServicesWithCatalog(servicesObj, catalogList) {
  if (!servicesObj || !Array.isArray(servicesObj.items) || !Array.isArray(catalogList) || catalogList.length === 0) return;

  servicesObj.items.forEach(item => {
    const itemId = (item.id || '').toLowerCase();
    const itemTitle = (item.title || '').toLowerCase();

    const matched = catalogList.find(s => {
      const sSlug = (s.slug || '').toLowerCase();
      const sName = (s.name || '').toLowerCase();

      if (itemId === 'pan_find' || itemId.includes('pan') || itemTitle.includes('lost pan') || itemTitle.includes('pan recovery')) {
        return sSlug.includes('pan') && (sSlug.includes('find') || s.category === 'pan_find');
      }
      if (itemId === 'aadhaar_pvc' || (itemTitle.includes('aadhaar') && !itemTitle.includes('pan'))) {
        return sSlug.includes('aadhaar') && !sSlug.includes('pan');
      }
      if (itemId === 'voter_id' || itemId.includes('voter') || itemTitle.includes('voter')) {
        return sSlug.includes('voter') || sName.includes('voter');
      }
      if (itemId === 'ayushman' || itemId.includes('ayushman') || itemTitle.includes('ayushman')) {
        return sSlug.includes('ayushman') || sName.includes('ayushman');
      }
      return false;
    });

    if (matched) {
      item.icon_type = matched.icon_type || (matched.icon_image ? 'image' : 'icon');
      if (matched.icon) item.icon = matched.icon;
      if (matched.icon_image) item.icon_image = matched.icon_image;
      if (matched.icon_image_url) item.icon_image_url = matched.icon_image_url;
      if (matched.icon_bg) item.icon_bg = matched.icon_bg;
      if (matched.icon_color) item.icon_color = matched.icon_color;
    }
  });
}

// Instant synchronous hydration from localStorage cache to prevent any flash of content on refresh
try {
  const cachedData = localStorage.getItem('utkal_landing_data');
  const cachedCatalog = JSON.parse(localStorage.getItem('utkal_services_cache') || '[]');
  if (cachedData) {
    const parsed = JSON.parse(cachedData);
    if (parsed.services && Array.isArray(cachedCatalog) && cachedCatalog.length > 0) {
      enrichServicesWithCatalog(parsed.services, cachedCatalog);
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => hydrateLandingPage(parsed));
    } else {
      hydrateLandingPage(parsed);
    }
  }
} catch (e) {}

document.addEventListener('DOMContentLoaded', () => {
  const fetchFreshData = async () => {
    try {
      const [landingRes, catRes] = await Promise.all([
        API.request('/landing-page').catch(() => null),
        API.request('/services').catch(() => null)
      ]);

      const data = landingRes && landingRes.data ? landingRes.data : null;
      let catalog = catRes && Array.isArray(catRes.data) ? catRes.data : null;

      if (catalog) {
        try { localStorage.setItem('utkal_services_cache', JSON.stringify(catalog)); } catch(e){}
      } else {
        try { catalog = JSON.parse(localStorage.getItem('utkal_services_cache') || '[]'); } catch(e){}
      }

      if (data) {
        if (catalog && data.services) {
          enrichServicesWithCatalog(data.services, catalog);
        }
        const newStr = JSON.stringify(data);
        const oldStr = localStorage.getItem('utkal_landing_data');
        if (newStr !== oldStr) {
          try { localStorage.setItem('utkal_landing_data', newStr); } catch (e) {}
          hydrateLandingPage(data);
        }
      }
    } catch (err) {
      console.warn('Using default fallback landing page content:', err.message);
    }
  };

  if ('requestIdleCallback' in window) {
    requestIdleCallback(fetchFreshData, { timeout: 1500 });
  } else {
    setTimeout(fetchFreshData, 50);
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
      const badgeIcon = h.badge_icon || 'fa-bolt';
      const badgeTag = h.badge_tag || 'FAST & AUTOMATED';
      heroBadge.innerHTML = `<span class="rz-badge-tag"><i class="fa-solid ${badgeIcon}"></i> ${escapeHtml(badgeTag)}</span> <span>${escapeHtml(h.badge_text)}</span>`;
    }

    const heroTitle = document.getElementById('heroTitle');
    if (heroTitle && (h.title_highlight || h.title_rest)) {
      const rest = h.title_rest || 'Automated';
      const highlight = h.title_highlight || 'PVC Card Printing';
      const suffix = h.title_suffix !== undefined ? h.title_suffix : '& Instant PAN Portal';
      heroTitle.innerHTML = `${escapeHtml(rest)} <span class="rz-hero-gradient-text">${escapeHtml(highlight)}</span> ${escapeHtml(suffix)}`;
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
          <i class="fa-solid ${safeToken(item.icon || 'fa-circle-check')}"></i>
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

        const iconType = svc.icon_type || (svc.icon_image ? 'image' : 'icon');
        const imgUrl = svc.icon_image_url || (svc.icon_image && (svc.icon_image.startsWith('http') ? svc.icon_image : `${STORAGE_BASE_URL}/${svc.icon_image}`));
        const iconBg = svc.icon_bg;
        const iconColor = svc.icon_color;

        let iconBoxHtml = '';
        if (iconType === 'image' && imgUrl) {
          const bgStyle = iconBg ? `background: ${iconBg};` : '';
          let pureIcon = (icon || 'id-card').replace(/^fa-(solid|regular|brands)\s+/, '').replace(/^fa-/, '');
          const fallbackIconClass = `fa-solid fa-${pureIcon || 'id-card'}`;
          iconBoxHtml = `
            <div class="rz-bento-icon ${color}" id="svcIconBox_${idx}" style="${bgStyle} overflow: hidden; padding: 6px; display: flex; align-items: center; justify-content: center;">
              <img src="${escapeHtml(imgUrl)}" alt="${escapeAttr(svc.title)}" style="width: 100%; height: 100%; object-fit: contain; display: block;" onerror="this.onerror=null; this.parentElement.innerHTML='<i class=\\'${escapeAttr(fallbackIconClass)}\\'></i>';">
            </div>
          `;
        } else {
          const customStyle = [
            iconBg ? `background: ${iconBg};` : '',
            iconColor ? `color: ${iconColor};` : ''
          ].filter(Boolean).join(' ');
          const styleAttr = customStyle ? `style="${customStyle}"` : '';
          let iconClean = (icon || 'fa-id-card').trim();
          if (!iconClean.startsWith('fa-solid ') && !iconClean.startsWith('fa-regular ') && !iconClean.startsWith('fa-brands ')) {
            const pure = iconClean.replace(/^fa-(solid|regular|brands)\s+/, '').replace(/^fa-/, '');
            iconClean = `fa-solid fa-${pure}`;
          }
          iconBoxHtml = `
            <div class="rz-bento-icon ${color}" id="svcIconBox_${idx}" ${styleAttr}>
              <i class="${escapeAttr(iconClean)}"></i>
            </div>
          `;
        }

        return `
        <div class="rz-bento-card ${colClass} border-${color}" data-category="${escapeAttr(category)}">
          <div class="rz-bento-badge-row">
            ${iconBoxHtml}
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
      if (typeof renderTestimonialDots === 'function') {
        renderTestimonialDots();
      }
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
    if (ft.email) setHref('footerEmailLink', `mailto:${escapeHtml(ft.email)}`);

    if (ft.facebook_link) setHref('footerSocialFacebook', ft.facebook_link);
    if (ft.twitter_link) setHref('footerSocialTwitter', ft.twitter_link);
    if (ft.whatsapp_link) setHref('footerSocialWhatsapp', ft.whatsapp_link);
    if (ft.telegram_link) setHref('footerSocialTelegram', ft.telegram_link);

    setHref('footerPrivacyLink', (ft.privacy_url && ft.privacy_url !== '#') ? ft.privacy_url : 'privacy.html');
    setHref('footerTermsLink', (ft.terms_url && ft.terms_url !== '#') ? ft.terms_url : 'terms.html');
    setHref('footerRefundLink', (ft.refund_url && ft.refund_url !== '#') ? ft.refund_url : 'refund.html');
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

// ==========================================
// User Reviews & Star Rating System
// ==========================================

const RATING_DESCRIPTIONS = {
  1: '★☆☆☆☆ 1/5 - Poor experience',
  2: '★★☆☆☆ 2/5 - Fair, needs improvement',
  3: '★★★☆☆ 3/5 - Good, satisfactory service',
  4: '★★★★☆ 4/5 - Very Good, high quality',
  5: '★★★★★ 5/5 - Excellent & Highly Recommended!'
};

function openReviewModal() {
  const modal = document.getElementById('reviewModal');
  if (!modal) return;

  // Pre-fill user data if logged in
  try {
    const user = (typeof API !== 'undefined' && API.getUser) ? API.getUser() : null;
    if (user && user.name) {
      const nameInput = document.getElementById('reviewAuthorName');
      if (nameInput && !nameInput.value) {
        nameInput.value = user.name;
      }
    }
  } catch (e) {}

  // Reset star rating to 5
  setReviewRating(5);
  
  // Clear alert box
  const alertBox = document.getElementById('reviewAlertBox');
  if (alertBox) alertBox.style.display = 'none';

  modal.classList.add('active');
  modal.style.display = 'flex';
  modal.style.opacity = '1';
  modal.style.pointerEvents = 'auto';
  document.body.style.overflow = 'hidden';
}

function closeReviewModal() {
  const modal = document.getElementById('reviewModal');
  if (!modal) return;
  modal.classList.remove('active');
  modal.style.display = 'none';
  modal.style.opacity = '0';
  modal.style.pointerEvents = 'none';
  document.body.style.overflow = '';
}

window.openReviewModal = openReviewModal;
window.closeReviewModal = closeReviewModal;

function setReviewRating(rating) {
  const input = document.getElementById('reviewRatingInput');
  if (input) input.value = rating;

  const desc = document.getElementById('ratingDescription');
  if (desc && RATING_DESCRIPTIONS[rating]) {
    desc.textContent = RATING_DESCRIPTIONS[rating];
  }

  const starItems = document.querySelectorAll('#starPicker .star-item');
  starItems.forEach(star => {
    const val = parseInt(star.getAttribute('data-val'), 10);
    if (val <= rating) {
      star.style.color = '#eab308';
      star.classList.add('active');
    } else {
      star.style.color = '#cbd5e1';
      star.classList.remove('active');
    }
  });
}

// Hover effect on stars
document.addEventListener('DOMContentLoaded', () => {
  const starPicker = document.getElementById('starPicker');
  if (starPicker) {
    const stars = starPicker.querySelectorAll('.star-item');
    stars.forEach(star => {
      star.addEventListener('mouseenter', () => {
        const hoverVal = parseInt(star.getAttribute('data-val'), 10);
        stars.forEach(s => {
          const val = parseInt(s.getAttribute('data-val'), 10);
          s.style.color = val <= hoverVal ? '#f59e0b' : '#cbd5e1';
        });
      });
    });

    starPicker.addEventListener('mouseleave', () => {
      const currentVal = parseInt(document.getElementById('reviewRatingInput')?.value || '5', 10);
      setReviewRating(currentVal);
    });
  }

  const modalEl = document.getElementById('reviewModal');
  if (modalEl) {
    modalEl.addEventListener('click', (e) => {
      if (e.target === modalEl) closeReviewModal();
    });
  }
});

async function handleReviewSubmit(e) {
  e.preventDefault();

  const btn = document.getElementById('btnSubmitReview');
  const alertBox = document.getElementById('reviewAlertBox');
  const authorName = document.getElementById('reviewAuthorName').value.trim();
  const role = document.getElementById('reviewRole').value.trim();
  const rating = parseInt(document.getElementById('reviewRatingInput').value, 10) || 5;
  const comment = document.getElementById('reviewComment').value.trim();

  if (!authorName || authorName.length < 2) {
    showReviewAlert('Please enter your full name.', 'error');
    return;
  }
  if (!comment || comment.length < 5) {
    showReviewAlert('Please write at least a few words for your review.', 'error');
    return;
  }

  // Set loading state
  const originalBtnText = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';

  try {
    const token = (typeof API !== 'undefined' && API.getToken) ? API.getToken() : null;
    const headers = {
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    };
    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    const apiUrl = (typeof API_BASE_URL !== 'undefined') ? `${API_BASE_URL}/reviews` : 'http://127.0.0.1:8000/api/reviews';
    const res = await fetch(apiUrl, {
      method: 'POST',
      headers: headers,
      body: JSON.stringify({
        author_name: authorName,
        role_or_business: role,
        rating: rating,
        comment: comment
      })
    });

    const data = await res.json();

    if (!res.ok) {
      throw new Error(data.message || 'Failed to submit review');
    }

    showReviewAlert('🎉 Thank you! Your review has been published live.', 'success');

    // Dynamically insert into the carousel track immediately
    prependReviewToCarousel(data.data || {
      author: authorName,
      role: role || 'Digital Center Retailer',
      initials: authorName.substring(0, 2).toUpperCase(),
      stars: rating,
      quote: comment
    });

    // Reset form
    document.getElementById('reviewForm').reset();
    const charEl = document.getElementById('charCount');
    if (charEl) charEl.textContent = '0/600';

    setTimeout(() => {
      closeReviewModal();
      const sec = document.getElementById('testimonialsSection');
      if (sec) {
        sec.scrollIntoView({ behavior: 'smooth' });
      }
    }, 1200);

  } catch (err) {
    showReviewAlert(err.message || 'Network error submitting review.', 'error');
  } finally {
    btn.disabled = false;
    btn.innerHTML = originalBtnText;
  }
}

function showReviewAlert(msg, type) {
  const alertBox = document.getElementById('reviewAlertBox');
  if (!alertBox) return;
  alertBox.style.display = 'block';
  if (type === 'success') {
    alertBox.style.background = '#dcfce7';
    alertBox.style.color = '#166534';
    alertBox.style.border = '1px solid #86efac';
  } else {
    alertBox.style.background = '#fee2e2';
    alertBox.style.color = '#991b1b';
    alertBox.style.border = '1px solid #fca5a5';
  }
  alertBox.innerHTML = msg;
}

function prependReviewToCarousel(rev) {
  const track = document.getElementById('testimonialsTrack') || document.querySelector('.testimonials-grid');
  if (!track) return;

  const starsHtml = Array.from({ length: rev.stars || 5 }).map(() => '<i class="fa-solid fa-star"></i>').join('');
  const cardHtml = `
    <div class="testimonial-card-modern" style="border: 2px solid #0066cc; box-shadow: 0 2px 4px rgba(0,102,204,0.04), 0 10px 24px -4px rgba(0,102,204,0.14), 0 24px 44px -8px rgba(0,102,204,0.08);">
      <div>
        <div class="testimonial-stars">
          ${starsHtml}
        </div>
        <p class="testimonial-quote">
          "${escapeHtml(rev.quote.replace(/^"|"$/g, ''))}"
        </p>
      </div>
      <div class="testimonial-author">
        <div class="author-avatar" style="background: linear-gradient(135deg, #0066cc, #38bdf8); color: #ffffff;">${escapeHtml(rev.initials || 'U')}</div>
        <div class="author-info">
          <h5>${escapeHtml(rev.author)} <span style="font-size:0.7rem; font-weight:700; color:#16a34a; background:#dcfce7; padding:2px 6px; border-radius:4px; margin-left:4px;">NEW</span></h5>
          <p>${escapeHtml(rev.role)}</p>
        </div>
      </div>
    </div>
  `;

  track.insertAdjacentHTML('afterbegin', cardHtml);

  if (typeof renderTestimonialDots === 'function') {
    renderTestimonialDots();
  }
  track.scrollTo({ left: 0, behavior: 'smooth' });
}
