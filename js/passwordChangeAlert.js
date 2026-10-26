/**
 * PASSWORD CHANGE ALERT
 * Shows a popup alert when user needs to change password
 * Use this in dashboard pages before the redirect happens
 */

function showPasswordChangeAlert() {
    const swalWithBootstrapButtons = Swal.mixin({
        customClass: {
            confirmButton: 'btn btn-primary',
            cancelButton: 'btn btn-secondary'
        },
        buttonsStyling: false
    });

    swalWithBootstrapButtons.fire({
        title: ' Security Update Required',
        html: `
            <div style="text-align: left;">
                <p style="font-size: 15px; color: #333; margin-bottom: 15px;">
                    <strong>Your account requires a password change.</strong>
                </p>
                <p style="font-size: 14px; color: #666; line-height: 1.6;">
                    For security reasons, you must update your password before accessing the dashboard.
                </p>
                <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin-top: 15px; border-radius: 4px;">
                    <p style="font-size: 12px; color: #856404; margin: 0;">
                        ⚠️ <strong>Note:</strong> This is a mandatory security requirement. You won't be able to access your dashboard until this is completed.
                    </p>
                </div>
            </div>
        `,
        icon: 'warning',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: true,
        confirmButtonText: 'Change Password Now',
        showCancelButton: false,
        didOpen: (modal) => {
            // Optional: Add animation
            const content = modal.querySelector('.swal2-html-container');
            if (content) {
                content.style.animation = 'slideDown 0.3s ease-out';
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            // Redirect to change password page
            window.location.href = 'changePassword.php';
        }
    });
}

// Alternative: Modal version without confirmation button (forces redirect)
function showPasswordChangeAlertForced() {
    Swal.fire({
        title: 'Password Change Required',
        html: `
            <div style="text-align: center;">
                <p style="font-size: 16px; color: #333; margin-bottom: 15px;">
                    <strong>Your password needs to be updated.</strong>
                </p>
                <p style="font-size: 14px; color: #666; margin-bottom: 20px;">
                    For account security, you must change your password before proceeding.
                </p>
                <div style="background: #e8f5e9; border-left: 4px solid #4caf50; padding: 12px; border-radius: 4px;">
                    <p style="font-size: 13px; color: #2e7d32; margin: 0;">
                        ✓ This is a one-time requirement
                    </p>
                </div>
            </div>
        `,
        icon: 'warning',
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: (modal) => {
            // Auto-redirect after 3 seconds
            setTimeout(() => {
                window.location.href = 'changePassword.php';
            }, 3000);
        }
    });
}

// Toast notification for password change reminder
function showPasswordChangeToast() {
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 5000,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.onmouseenter = Swal.stopTimer;
            toast.onmouseleave = Swal.resumeTimer;
        }
    });

    Toast.fire({
        icon: 'warning',
        title: 'Password change required',
        html: '<span style="font-size: 13px;">You need to change your password. <a href="changePassword.php" style="color: #667eea; font-weight: bold;">Change now</a></span>'
    });
}

// Function to check and show alert if needed (call this in your dashboard pages)
function checkAndShowPasswordChangeAlert() {
    // This function can be called on page load to check password status
    // You can make an AJAX call to check status, or let the server-side redirect handle it
    
    fetch('checkPasswordStatus.php', {
        method: 'GET'
    })
    .then(response => response.json())
    .then(data => {
        if (data.passwordChangeRequired) {
            showPasswordChangeAlert();
        }
    })
    .catch(error => console.log('Password check skipped'));
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        showPasswordChangeAlert,
        showPasswordChangeAlertForced,
        showPasswordChangeToast,
        checkAndShowPasswordChangeAlert
    };
}