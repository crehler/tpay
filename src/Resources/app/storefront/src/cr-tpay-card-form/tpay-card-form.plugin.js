import Plugin from 'src/plugin-system/plugin.class';
import JSEncrypt from './jsencrypt.min';

const MAX_CARD_NUMBER_LENGTH = 16;
const CONFIRM_FORM_ID = 'confirmOrderForm';

export default class TpayCardForm extends Plugin {
    static options = {
        cardNumberSelector: '[data-tpay-card-number]',
        cvcSelector: '[data-tpay-cvc]',
        monthSelector: '[data-tpay-expiration-month]',
        yearSelector: '[data-tpay-expiration-year]',
        encryptedSelector: '[data-tpay-encrypted-card]',
        publicKeySelector: '[data-tpay-cards-api]',
        cardDataContainerSelector: '[data-tpay-card-data]',
        savedCardTokenSelector: '#tpayCardToken',
    };

    init() {
        this._publicKeyInput = this.el.querySelector(this.options.publicKeySelector);
        this._encryptedInput = this.el.querySelector(this.options.encryptedSelector);
        this._cardNumber = this.el.querySelector(this.options.cardNumberSelector);
        this._cvc = this.el.querySelector(this.options.cvcSelector);
        this._month = this.el.querySelector(this.options.monthSelector);
        this._year = this.el.querySelector(this.options.yearSelector);
        this._cardDataContainer = this.el.querySelector(this.options.cardDataContainerSelector);
        this._savedCardTokenInput = document.querySelector(this.options.savedCardTokenSelector);
        this._confirmForm = document.getElementById(CONFIRM_FORM_ID);

        if (!this._publicKeyInput || !this._encryptedInput || !this._confirmForm) {
            return;
        }

        this._registerFormatters();
        this._registerValidators();
        this._registerSubmitHandler();
        this._observeSavedCardSelection();
        this._syncCardDataVisibility();
    }

    _registerFormatters() {
        if (this._cardNumber) {
            this._cardNumber.addEventListener('keypress', (event) => {
                const value = event.target.value.replace(/\s/g, '');
                if (value.length >= MAX_CARD_NUMBER_LENGTH) {
                    event.preventDefault();
                    event.stopPropagation();
                }
            });

            this._cardNumber.addEventListener('keyup', (event) => {
                const currentPositionStart = event.target.selectionStart;
                const currentLength = event.target.value.length;
                const value = event.target.value.replace(/\s/g, '');
                const parts = value.slice(0, MAX_CARD_NUMBER_LENGTH).match(/\d{1,4}/g) || [];
                event.target.value = parts.join(' ');
                const newLength = event.target.value.length;
                let pos = currentPositionStart + (newLength - currentLength);
                if (pos < 0) {
                    pos = 0;
                }
                event.target.setSelectionRange(pos, pos);
            });
        }

        if (this._cvc) {
            this._cvc.addEventListener('keyup', (event) => {
                event.target.value = event.target.value.replace(/\s/g, '').slice(0, 4);
            });
        }
    }

    _registerValidators() {
        if (this._cardNumber) {
            this._cardNumber.addEventListener('change', () => this._validateCardNumber());
        }
        if (this._cvc) {
            this._cvc.addEventListener('change', () => this._validateCvc());
        }
        if (this._month) {
            this._month.addEventListener('change', () => this._validateExpiration());
        }
        if (this._year) {
            this._year.addEventListener('change', () => this._validateExpiration());
        }
    }

    _registerSubmitHandler() {
        this._confirmForm.addEventListener('submit', (event) => {
            if (this._isSavedCardSelected()) {
                this._encryptedInput.value = '';
                return;
            }

            this._validateCardNumber();
            this._validateCvc();
            this._validateExpiration();

            const hasErrors = this.el.querySelectorAll('[data-tpay-validation-error]').length > 0;

            if (hasErrors) {
                event.preventDefault();
                event.stopPropagation();
                this._confirmForm.classList.remove('loading');
                return;
            }

            try {
                this._updateEncryptedCard();
            } catch (err) {
                event.preventDefault();
                event.stopPropagation();
                this._confirmForm.classList.remove('loading');
                this._addGlobalError(err.message || 'Card encryption failed');
                return;
            }

            if (this._savedCardTokenInput) {
                this._savedCardTokenInput.value = '';
            }
        });
    }

    _observeSavedCardSelection() {
        if (!this._savedCardTokenInput) {
            return;
        }
        const observer = new MutationObserver(() => this._syncCardDataVisibility());
        observer.observe(this._savedCardTokenInput, { attributes: true, attributeFilter: ['value'] });
        document.addEventListener('click', (event) => {
            if (event.target.closest('.tpay-saved-cards .dropdown-item')) {
                setTimeout(() => this._syncCardDataVisibility(), 0);
            }
        });
    }

    _syncCardDataVisibility() {
        if (!this._cardDataContainer) {
            return;
        }
        if (this._isSavedCardSelected()) {
            this._cardDataContainer.style.display = 'none';
        } else {
            this._cardDataContainer.style.display = '';
        }
    }

    _isSavedCardSelected() {
        return this._savedCardTokenInput !== null
            && this._savedCardTokenInput.value !== '';
    }

    _updateEncryptedCard() {
        const publicKeyBase64 = this._publicKeyInput.value.replace(/\s/g, '');
        if (publicKeyBase64 === '') {
            throw new Error('Public key missing');
        }

        const encrypt = new JSEncrypt();
        encrypt.setPublicKey(atob(publicKeyBase64));

        const cardNumber = this._cardNumber.value.replace(/\s/g, '');
        const mm = String(this._month.value).padStart(2, '0');
        const yy = String(this._year.value).slice(-2);
        const cvc = this._cvc.value.replace(/\s/g, '');

        const data = [cardNumber, `${mm}/${yy}`, cvc, document.location.origin].join('|');
        const encrypted = encrypt.encrypt(data);

        if (encrypted === false || encrypted === '') {
            throw new Error('Card encryption returned empty result');
        }

        this._encryptedInput.value = encrypted;
    }

    _validateCardNumber() {
        const value = (this._cardNumber?.value || '').replace(/\s/g, '');
        const valid = /^\d{16}$/.test(value);
        this._toggleError(this._cardNumber, valid);
    }

    _validateCvc() {
        const value = (this._cvc?.value || '').replace(/\s/g, '');
        const valid = /^\d{3,4}$/.test(value);
        this._toggleError(this._cvc, valid);
    }

    _validateExpiration() {
        if (!this._month || !this._year) {
            return;
        }
        const mm = parseInt(this._month.value, 10);
        const yyyy = parseInt(this._year.value, 10);

        if (Number.isNaN(mm) || Number.isNaN(yyyy)) {
            this._toggleError(this._month, false);
            return;
        }

        const now = new Date();
        const monthValid = mm >= 1 && mm <= 12;
        const yearValid = yyyy >= now.getFullYear();
        const futureValid = yyyy > now.getFullYear() || mm >= (now.getMonth() + 1);

        const valid = monthValid && yearValid && futureValid;
        this._toggleError(this._month, valid);
        this._toggleError(this._year, valid);
    }

    _toggleError(field, valid) {
        if (!field) {
            return;
        }
        const wrapper = field.closest('[data-tpay-field]');
        if (!wrapper) {
            return;
        }
        const errorContainer = wrapper.querySelector('[data-tpay-error-container]');
        if (!errorContainer) {
            return;
        }

        if (valid) {
            errorContainer.innerHTML = '';
            field.classList.remove('is-invalid');
            return;
        }

        field.classList.add('is-invalid');
        const msg = field.dataset.validationError || 'Invalid value';
        errorContainer.innerHTML = `<div class="invalid-feedback d-block" data-tpay-validation-error="">${msg}</div>`;
    }

    _addGlobalError(message) {
        const target = this.el.querySelector('[data-tpay-global-error]');
        if (!target) {
            return;
        }
        target.innerHTML = `<div class="alert alert-danger" data-tpay-validation-error="">${message}</div>`;
    }
}
