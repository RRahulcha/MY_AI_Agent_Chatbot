// Password Validation Functions
function validatePasswordStrength(password) {
    const requirements = {
        minLength: password.length >= 8,
        hasUpperCase: /[A-Z]/.test(password),
        hasLowerCase: /[a-z]/.test(password),
        hasNumbers: /[0-9]/.test(password),
        hasSpecialChar: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)
    };

    return requirements;
}

function isPasswordStrong(password) {
    const requirements = validatePasswordStrength(password);
    // For strong password: min 8 chars, uppercase, lowercase, numbers, special chars
    return Object.values(requirements).every(req => req === true);
}

function getPasswordStrengthMessage(password) {
    const requirements = validatePasswordStrength(password);
    const messages = [];

    if (!requirements.minLength) {
        messages.push('❌ At least 8 characters');
    } else {
        messages.push('✓ At least 8 characters');
    }

    if (!requirements.hasUpperCase) {
        messages.push('❌ At least one uppercase letter (A-Z)');
    } else {
        messages.push('✓ At least one uppercase letter (A-Z)');
    }

    if (!requirements.hasLowerCase) {
        messages.push('❌ At least one lowercase letter (a-z)');
    } else {
        messages.push('✓ At least one lowercase letter (a-z)');
    }

    if (!requirements.hasNumbers) {
        messages.push('❌ At least one number (0-9)');
    } else {
        messages.push('✓ At least one number (0-9)');
    }

    if (!requirements.hasSpecialChar) {
        messages.push('❌ At least one special character (!@#$%^&*...)');
    } else {
        messages.push('✓ At least one special character (!@#$%^&*...)');
    }

    return messages;
}

// Update strength indicator in real-time
function updatePasswordStrengthIndicator(inputId, indicatorId, messagesId) {
    const passwordInput = document.getElementById(inputId);
    const indicator = document.getElementById(indicatorId);
    const messagesDiv = document.getElementById(messagesId);

    if (!passwordInput) return;

    passwordInput.addEventListener('input', function() {
        const password = this.value;
        const isStrong = isPasswordStrong(password);
        const messages = getPasswordStrengthMessage(password);

        // Update indicator
        if (indicator) {
            if (password.length === 0) {
                indicator.className = 'password-strength-indicator';
            } else if (isStrong) {
                indicator.className = 'password-strength-indicator strong';
            } else {
                indicator.className = 'password-strength-indicator weak';
            }
        }

        // Update messages
        if (messagesDiv) {
            messagesDiv.innerHTML = '<strong>Password Requirements:</strong><br>' + 
                                   messages.join('<br>');
        }
    });
}

// Validate password match
function validatePasswordMatch(newPasswordId, confirmPasswordId) {
    const newPassword = document.getElementById(newPasswordId).value;
    const confirmPassword = document.getElementById(confirmPasswordId).value;
    
    return newPassword === confirmPassword;
}