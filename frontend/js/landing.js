/**
 * Utkal Print Portal - Dynamic Landing Page Hydration Client
 * Seamlessly hydrates http://127.0.0.1:3000/ with live Admin CMS settings
 */

document.addEventListener('DOMContentLoaded', async () => {
  try {
    const res = await API.getLandingPageContent();
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
  }

  // 2. Hero Section
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

  // 3. Core Services
  if (data.services) {
    const s = data.services;
    setText('servicesTag', s.tag);
    setText('servicesTitle', s.title);
    setText('servicesDesc', s.desc);

    const grid = document.getElementById('servicesGrid');
    if (grid && Array.isArray(s.items) && s.items.length > 0) {
      // Hydrate existing cards
      s.items.forEach((item, idx) => {
        const cardTitle = document.getElementById(`svcTitle_${idx}`);
        if (cardTitle) cardTitle.textContent = item.title;

        const cardBadge = document.getElementById(`svcBadge_${idx}`);
        if (cardBadge) cardBadge.textContent = item.badge || item.price;

        const cardDesc = document.getElementById(`svcDesc_${idx}`);
        if (cardDesc) cardDesc.textContent = item.desc;
      });
    }
  }

  // 4. Rates & Pricing
  if (data.pricing) {
    const p = data.pricing;
    setText('pricingTag', p.tag);
    setText('pricingTitle', p.title);
    setText('pricingDesc', p.desc);

    if (Array.isArray(p.cards)) {
      p.cards.forEach((card, idx) => {
        setText(`pricingCardTitle_${idx}`, card.title);
        setText(`pricingCardPrice_${idx}`, card.price);
        setText(`pricingCardSpeed_${idx}`, card.speed);
        setText(`pricingCardFormat_${idx}`, card.format);
        setText(`pricingCardDesc_${idx}`, card.desc);
      });
    }
  }

  // 5. Workflow (How it Works)
  if (data.workflow) {
    const w = data.workflow;
    setText('workflowTag', w.tag);
    setText('workflowTitle', w.title);

    if (Array.isArray(w.steps)) {
      w.steps.forEach((step, idx) => {
        setText(`stepNum_${idx}`, step.step_num);
        setText(`stepTitle_${idx}`, step.title);
        setText(`stepDesc_${idx}`, step.desc);
      });
    }
  }

  // 6. Testimonials
  if (data.testimonials) {
    const t = data.testimonials;
    setText('testimonialsTag', t.tag);
    setText('testimonialsTitle', t.title);
    setText('testimonialsDesc', t.desc);

    if (Array.isArray(t.items)) {
      t.items.forEach((item, idx) => {
        setText(`reviewQuote_${idx}`, `"${item.quote.replace(/^"|"$/g, '')}"`);
        setText(`reviewAuthor_${idx}`, item.author);
        setText(`reviewRole_${idx}`, item.role);
      });
    }
  }

  // 7. FAQ Section
  if (data.faq) {
    const f = data.faq;
    setText('faqTag', f.tag);
    setText('faqTitle', f.title);

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

  // 8. Bottom CTA Banner
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

  // 9. Footer & Contacts
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
