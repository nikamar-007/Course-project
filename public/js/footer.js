function initFooter() {
  console.log("Инициализация футера...");

  const columns = document.querySelectorAll('.footer-column');
  const cards = {
    data: document.getElementById('dataCard'),
    privacy: document.getElementById('privacyCard'),
    contact: document.getElementById('contactCard')
  };
  const overlay = document.getElementById('cardOverlay');

  let activeCard = null;

  window.closeAllCards = function() {
    console.log("Закрытие всех карточек");

    Object.values(cards).forEach(card => {
      card.classList.remove('active');
    });

    overlay.classList.remove('active');

    columns.forEach(col => {
      col.classList.remove('active');
    });

    activeCard = null;

    document.body.style.overflow = '';
  };

  function openCard(cardType) {
    console.log("Открытие карточки:", cardType);

    if (activeCard === cardType) {
      closeAllCards();
      return;
    }

    closeAllCards();

    overlay.classList.add('active');

    if (cards[cardType]) {
      cards[cardType].classList.add('active');

      document.querySelector(`.footer-column[data-card="${cardType}"]`)
        .classList.add('active');

      activeCard = cardType;

      document.body.style.overflow = 'hidden';
    }
  }

  if (columns.length === 0) {
    console.error("Не найдены столбцы футера!");
  }

  if (!cards.data || !cards.privacy || !cards.contact) {
    console.error("Не найдены карточки футера!");
  }

  if (!overlay) {
    console.error("Не найден оверлей!");
  }

  columns.forEach(column => {
    column.addEventListener('click', function(e) {
      e.stopPropagation();
      const cardType = this.getAttribute('data-card');
      console.log("Клик по столбцу:", cardType);
      openCard(cardType);
    });
  });

  document.querySelectorAll('.card-close').forEach(btn => {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      console.log("Клик по закрытию карточки");
      closeAllCards();
    });
  });

  overlay.addEventListener('click', function(e) {
    e.stopPropagation();
    console.log("Клик по оверлею");
    closeAllCards();
  });

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      console.log("Нажата клавиша ESC");
      closeAllCards();
    }
  });

  Object.values(cards).forEach(card => {
    card.addEventListener('click', function(e) {
      e.stopPropagation();
    });
  });

  console.log("Скрипт футера успешно инициализирован");
}