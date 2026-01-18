function showLandingCard() {
  const landingOverlay = document.getElementById('landingOverlay');
  if (!landingOverlay) {
    loadLandingCard().then(() => {
      const newOverlay = document.getElementById('landingOverlay');
      if (newOverlay) {
        newOverlay.style.display = 'flex';
        setTimeout(() => {
          newOverlay.classList.remove('hidden');
        }, 10);
      }
    });
    return;
  }
  
  landingOverlay.style.display = 'flex';
  setTimeout(() => {
    landingOverlay.classList.remove('hidden');
  }, 10);
}

function hideLandingCard() {
  const landingOverlay = document.getElementById('landingOverlay');
  if (!landingOverlay) return;
  
  landingOverlay.classList.add('hidden');
  
  setTimeout(() => {
    landingOverlay.style.display = 'none';
  }, 800);
}

function loadLandingCard() {
  return fetch('/landing-card.html')
    .then(r => r.text())
    .then(html => {
      const container = document.getElementById('landingCardContainer');
      if (!container) {
        const newContainer = document.createElement('div');
        newContainer.id = 'landingCardContainer';
        document.body.appendChild(newContainer);
        newContainer.innerHTML = html;
      } else {
        container.innerHTML = html;
      }
      initLandingCardEvents();
      return true;
    })
    .catch(err => {
      console.error('Ошибка загрузки landing-card:', err);
      return false;
    });
}

function initLandingCardEvents() {
  const landingOverlay = document.getElementById('landingOverlay');
  const startExploringBtn = document.getElementById('startExploringBtn');
  
  if (startExploringBtn) {
    startExploringBtn.addEventListener('click', hideLandingCard);
  }
  
  if (landingOverlay) {
    landingOverlay.addEventListener('click', function(e) {
      if (e.target === landingOverlay) {
        hideLandingCard();
      }
    });
  }
  
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && landingOverlay && landingOverlay.style.display === 'flex') {
      hideLandingCard();
    }
  });
}

function setupLogoClickListener() {
  const logoSelectors = ['.logo', '#mainLogo', '#adminLogo', '.app-header .logo', '.admin-page-header .logo'];
  
  for (const selector of logoSelectors) {
    const logo = document.querySelector(selector);
    if (logo) {
      
      logo.removeEventListener('click', handleLogoClick);
      
      logo.addEventListener('click', handleLogoClick);
      logo.style.cursor = 'pointer';
      logo.title = 'Нажмите для информации о сайте';
      break; 
    }
  }
}

function handleLogoClick(e) {
  e.preventDefault();
  e.stopPropagation();
  showLandingCard();
}

document.addEventListener('DOMContentLoaded', function() {

  loadLandingCard();
  
  setupLogoClickListener();
  
  setTimeout(setupLogoClickListener, 1000);
  setTimeout(setupLogoClickListener, 3000);
  
  const observer = new MutationObserver(function(mutations) {
    let shouldSetup = false;
    for (let mutation of mutations) {
      if (mutation.type === 'childList') {
        shouldSetup = true;
        break;
      }
    }
    if (shouldSetup) {
      setupLogoClickListener();
    }
  });
  
  observer.observe(document.body, { childList: true, subtree: true });
});

window.showLandingCard = showLandingCard;
window.hideLandingCard = hideLandingCard;