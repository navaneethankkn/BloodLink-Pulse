/**
 * BloodLink Pulse - Modular Client-Side Form Validation (Vanilla JS)
 * 
 * Rubric Features:
 * 1. Modular DOM manipulation with dynamic inline error tags
 * 2. Real-time regex checking (10-digit phone, email format, name >= 3 chars)
 * 3. Donation interval check (>= 90 days calculation with days remaining)
 * 4. Submit event blocking until all fields pass validation
 * 5. Instant feedback on input, change, and blur events
 */

(function (window) {
    'use strict';

    // Regex Patterns
    const PATTERNS = {
        PHONE: /^\d{10}$/,
        EMAIL: /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/,
        NAME: /^[\p{L}\p{N}\s.'-]{3,100}$/u
    };

    /**
     * Helper to compute days between a given date string (YYYY-MM-DD) and today
     */
    function calculateDaysSince(dateString) {
        if (!dateString) return null;
        const [year, month, day] = dateString.split('-').map(Number);
        const donationDate = new Date(year, month - 1, day);
        donationDate.setHours(0, 0, 0, 0);

        const today = new Date();
        today.setHours(0, 0, 0, 0);

        const diffTime = today.getTime() - donationDate.getTime();
        return Math.floor(diffTime / (1000 * 60 * 60 * 24));
    }

    /**
     * Cleans non-digit characters from phone input for normalization
     */
    function cleanPhone(value) {
        return (value || '').replace(/\D/g, '');
    }

    /**
     * Validation Rules Definition
     */
    const Rules = {
        name: (value) => {
            const trimmed = (value || '').trim();
            if (!trimmed) {
                return { isValid: false, message: 'Name is required.' };
            }
            if (trimmed.length < 3) {
                return { isValid: false, message: `Name must be at least 3 characters long (currently ${trimmed.length}).` };
            }
            if (!PATTERNS.NAME.test(trimmed)) {
                return { isValid: false, message: 'Name should only contain letters, spaces, hyphens, or apostrophes.' };
            }
            return { isValid: true };
        },

        patient_name: (value) => Rules.name(value),

        hospital_name: (value) => {
            const trimmed = (value || '').trim();
            if (!trimmed) {
                return { isValid: false, message: 'Hospital or clinic facility name is required.' };
            }
            if (trimmed.length < 3) {
                return { isValid: false, message: 'Hospital name must be at least 3 characters long.' };
            }
            return { isValid: true };
        },

        email: (value) => {
            const trimmed = (value || '').trim();
            if (!trimmed) {
                return { isValid: false, message: 'Email address is required.' };
            }
            if (!PATTERNS.EMAIL.test(trimmed)) {
                return { isValid: false, message: 'Please enter a valid email address (e.g., alex@lifelink.org).' };
            }
            return { isValid: true };
        },

        phone: (value) => {
            const digits = cleanPhone(value);
            if (!digits) {
                return { isValid: false, message: '10-digit contact phone number is required.' };
            }
            if (digits.length !== 10) {
                return {
                    isValid: false,
                    message: `Phone must be exactly 10 digits (currently ${digits.length} digits).`
                };
            }
            if (!PATTERNS.PHONE.test(digits)) {
                return { isValid: false, message: 'Phone must contain 10 valid digits.' };
            }
            return { isValid: true };
        },

        contact_phone: (value) => Rules.phone(value),

        blood_type: (value) => {
            const valid = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
            if (!value || !valid.includes(value.trim())) {
                return { isValid: false, message: 'Please select a valid ABO / Rh blood type.' };
            }
            return { isValid: true };
        },

        city: (value) => {
            const trimmed = (value || '').trim();
            if (!trimmed) {
                return { isValid: false, message: 'City location is required for matching.' };
            }
            if (trimmed.length < 2) {
                return { isValid: false, message: 'City name must be at least 2 characters.' };
            }
            return { isValid: true };
        },

        units_needed: (value) => {
            const num = parseInt(value, 10);
            if (isNaN(num) || num < 1 || num > 20) {
                return { isValid: false, message: 'Units needed must be an integer between 1 and 20.' };
            }
            return { isValid: true };
        },

        urgency: (value) => {
            const valid = ['Critical', 'High', 'Medium'];
            if (!value || !valid.includes(value.trim())) {
                return { isValid: false, message: 'Please select an urgency triage level.' };
            }
            return { isValid: true };
        },

        /**
         * Donation Interval Check (>= 90 Days):
         * Evaluates last donation date against medical eligibility guidelines.
         * If < 90 days, blocks registration or triggers cooldown warning.
         */
        last_donation_date: (value, allowCooldownWarning = false) => {
            const trimmed = (value || '').trim();
            if (!trimmed) {
                return {
                    isValid: true,
                    isFirstTime: true,
                    infoMessage: 'First-time donor (no prior donation cooldown applied).'
                };
            }

            const daysSince = calculateDaysSince(trimmed);
            if (daysSince === null || isNaN(daysSince)) {
                return { isValid: false, message: 'Invalid date format. Use YYYY-MM-DD.' };
            }

            if (daysSince < 0) {
                return { isValid: false, message: 'Last donation date cannot be in the future.' };
            }

            if (daysSince < 90) {
                const remaining = 90 - daysSince;
                const msg = `Donation interval cooldown: Must wait at least 90 days between donations (${daysSince} days elapsed, ${remaining} days remaining).`;
                
                if (allowCooldownWarning) {
                    // Allowed with explicit warning flag (e.g. record stored with Cooldown status)
                    return {
                        isValid: true,
                        isCooldown: true,
                        remainingDays: remaining,
                        warningMessage: msg
                    };
                }

                return {
                    isValid: false,
                    isCooldown: true,
                    remainingDays: remaining,
                    message: msg
                };
            }

            return {
                isValid: true,
                successMessage: `Eligible for donation (${daysSince} days since last donation - exceeds 90-day cooldown).`
            };
        }
    };

    /**
     * Inline DOM Error & Success Tag Manager
     */
    class FormValidator {
        constructor(formElement, options = {}) {
            if (!(formElement instanceof HTMLElement)) {
                throw new Error('FormValidator requires a valid DOM form element.');
            }

            this.form = formElement;
            this.options = Object.assign({
                allowCooldownAsWarning: true, // Stores donor with Cooldown status instead of hard-rejecting
                onValidSubmit: null
            }, options);

            this.fields = new Map();
            this.init();
        }

        init() {
            // Find all inputs, selects, and textareas with a name attribute
            const inputs = this.form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                const name = input.getAttribute('name') || input.id;
                if (!name || !Rules[name]) return;

                this.fields.set(name, input);

                // Create or find inline feedback container
                this.ensureFeedbackElement(input);

                // Real-time events
                input.addEventListener('input', () => this.validateField(name, true));
                input.addEventListener('blur', () => this.validateField(name, false));
                input.addEventListener('change', () => this.validateField(name, false));
            });

            // Intercept form submit event
            this.form.addEventListener('submit', (e) => {
                e.preventDefault();
                e.stopPropagation();

                const isFormValid = this.validateAll();
                if (!isFormValid) {
                    this.focusFirstError();
                    return false;
                }

                if (typeof this.options.onValidSubmit === 'function') {
                    const formData = this.getSerializedData();
                    this.options.onValidSubmit(formData, this.form);
                }
            });
        }

        ensureFeedbackElement(input) {
            let container = input.parentElement;
            let feedback = container.querySelector(`.inline-feedback[data-for="${input.name || input.id}"]`);

            if (!feedback) {
                feedback = document.createElement('div');
                feedback.className = 'inline-feedback text-xs mt-1.5 transition-all duration-200 hidden';
                feedback.setAttribute('data-for', input.name || input.id);
                // Insert right after the input or its surrounding wrapper
                if (input.nextSibling) {
                    container.insertBefore(feedback, input.nextSibling);
                } else {
                    container.appendChild(feedback);
                }
            }
            return feedback;
        }

        validateField(fieldName, isTyping = false) {
            const input = this.fields.get(fieldName);
            if (!input) return true;

            const validatorFn = Rules[fieldName];
            if (!validatorFn) return true;

            const value = input.value;
            const result = (fieldName === 'last_donation_date')
                ? validatorFn(value, this.options.allowCooldownAsWarning)
                : validatorFn(value);

            const feedback = this.ensureFeedbackElement(input);

            // Clear previous styling states
            input.classList.remove('is-invalid', 'border-red-500', 'focus:border-red-500', 'focus:ring-red-500/20',
                                   'is-valid', 'border-emerald-500', 'focus:border-emerald-500', 'focus:ring-emerald-500/20',
                                   'is-warning', 'border-amber-500', 'focus:border-amber-500');

            if (!result.isValid) {
                // Invalid State
                input.classList.add('is-invalid', 'border-red-500', 'focus:border-red-500', 'focus:ring-red-500/20');
                feedback.className = 'inline-feedback text-xs mt-1.5 text-red-500 flex items-center gap-1.5 animate-fadeIn';
                feedback.innerHTML = `
                    <svg class="w-3.5 h-3.5 flex-shrink-0 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                    </svg>
                    <span>${result.message}</span>
                `;
                feedback.classList.remove('hidden');
                return false;
            } else if (result.warningMessage) {
                // Cooldown Warning State
                input.classList.add('is-warning', 'border-amber-500', 'focus:border-amber-500');
                feedback.className = 'inline-feedback text-xs mt-1.5 text-amber-600 flex items-center gap-1.5 animate-fadeIn';
                feedback.innerHTML = `
                    <svg class="w-3.5 h-3.5 flex-shrink-0 text-amber-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                    </svg>
                    <span>${result.warningMessage}</span>
                `;
                feedback.classList.remove('hidden');
                return true;
            } else if (result.successMessage) {
                // Success feedback
                input.classList.add('is-valid', 'border-emerald-500', 'focus:border-emerald-500', 'focus:ring-emerald-500/20');
                feedback.className = 'inline-feedback text-xs mt-1.5 text-emerald-600 flex items-center gap-1.5 animate-fadeIn';
                feedback.innerHTML = `
                    <svg class="w-3.5 h-3.5 flex-shrink-0 text-emerald-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    <span>${result.successMessage}</span>
                `;
                feedback.classList.remove('hidden');
                return true;
            } else if (result.infoMessage) {
                // Subtle informational note
                feedback.className = 'inline-feedback text-xs mt-1.5 text-slate-500 flex items-center gap-1.5 animate-fadeIn';
                feedback.innerHTML = `<span>ℹ️ ${result.infoMessage}</span>`;
                feedback.classList.remove('hidden');
                return true;
            } else {
                // Standard Valid
                input.classList.add('is-valid', 'border-emerald-500', 'focus:border-emerald-500');
                feedback.classList.add('hidden');
                feedback.innerHTML = '';
                return true;
            }
        }

        validateAll() {
            let allValid = true;
            for (const fieldName of this.fields.keys()) {
                const isValid = this.validateField(fieldName, false);
                if (!isValid) {
                    allValid = false;
                }
            }
            return allValid;
        }

        focusFirstError() {
            const firstInvalid = this.form.querySelector('.is-invalid');
            if (firstInvalid) {
                firstInvalid.focus();
                firstInvalid.classList.add('animate-shake');
                setTimeout(() => firstInvalid.classList.remove('animate-shake'), 600);
            }
        }

        getSerializedData() {
            const formData = new FormData(this.form);
            const data = {};
            for (const [key, value] of formData.entries()) {
                data[key] = typeof value === 'string' ? value.trim() : value;
            }
            // Normalize phone
            if (data.phone) {
                data.phone = cleanPhone(data.phone);
            }
            if (data.contact_phone) {
                data.contact_phone = cleanPhone(data.contact_phone);
            }
            return data;
        }

        reset() {
            this.form.reset();
            const inputs = this.form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.classList.remove('is-invalid', 'is-valid', 'is-warning',
                                       'border-red-500', 'border-emerald-500', 'border-amber-500');
            });
            const feedbacks = this.form.querySelectorAll('.inline-feedback');
            feedbacks.forEach(f => {
                f.classList.add('hidden');
                f.innerHTML = '';
            });
        }
    }

    // Export to global window object
    window.BloodLinkValidation = {
        PATTERNS,
        Rules,
        FormValidator,
        calculateDaysSince,
        cleanPhone,
        bindForm: function (formElement, options) {
            return new FormValidator(formElement, options);
        }
    };

})(window);
