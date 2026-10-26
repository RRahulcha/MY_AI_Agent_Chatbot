<!-- Password Change Alert Script -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    .password-change-popup {
        border-radius: 12px !important;
        max-width: 400px !important;
        width: 90% !important;
    }
    
    .password-change-popup .swal2-icon {
    width: 50px !important;
    height: 50px !important;
    margin: 10px auto 12px !important;
   }
    
    .password-change-popup .swal2-title {
        font-size: 18px !important;
        font-weight: 600 !important;
        color: #2d3748 !important;
        margin-bottom: 12px !important;
    }
    
    .password-change-popup .swal2-html-container {
        font-size: 13px !important;
        color: #4a5568 !important;
        line-height: 1.5 !important;
        padding: 0 10px !important;
    }
    
    .password-change-popup .swal2-actions {
        margin-top: 20px !important;
    }
    
    .password-change-popup .swal2-confirm {
     background: #667eea !important;   
     border: none !important;
     border-radius: 9px !important;
     padding: 10px 20px !important;    
     font-size: 16px !important;       
     font-weight: 600 !important;
     box-shadow: 0 3px 8px rgba(102, 126, 234, 0.3) !important;
     transition: all 0.2s ease !important;
    }
    
    .password-change-popup .swal2-confirm:hover {
        background: linear-gradient(135deg, #5568d3 0%, #6a5acd 100%) !important;
        box-shadow: 0 6px 16px rgba(102, 126, 234, 0.5) !important;
        transform: translateY(-2px);
    }
    
    .pwd-warning-box {
        background: #fff8f0;
        border-left: 4px solid #f59e0b;
        padding: 10px 12px;
        border-radius: 6px;
        margin-top: 12px;
        text-align: left;
    }
    
    .pwd-warning-box p {
        margin: 0;
        font-size: 12px;
        color: #92400e;
        line-height: 1.4;
    }
    
    .pwd-warning-box strong {
        font-weight: 600;
        color: #78350f;
    }
</style>

<script>
    // Check if password change is required
    if (typeof window.passwordChangeRequired !== 'undefined' && window.passwordChangeRequired === true) {
        // Wait for page to fully load
        window.addEventListener('load', function() {
            setTimeout(function() {
                Swal.fire({
                    title: 'Password Change Required',
                    html: `
                        <div style="text-align: center;">
                            <p style="font-size: 13px; color: #4a5568; margin-bottom: 6px; line-height: 1.5;">
                                For security reasons, you must update your Information and password before continuing.
                            </p>
                            <div class="pwd-warning-box">
                                <p><strong>⚠ Important:</strong> Dashboard access is restricted until completed.</p>
                            </div>
                        </div>
                    `,
                    icon: 'warning',
                    iconColor: '#f59e0b',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    allowEnterKey: true,
                    showConfirmButton: true,
                    confirmButtonText: 'Change Password Now',
                    customClass: {
                        popup: 'password-change-popup'
                    },
                    backdrop: 'rgba(0, 0, 0, 0.2)',
                    didOpen: () => {
                        document.body.style.overflow = 'hidden';
                        // Disable interactions with page content
                        const main = document.querySelector('.main-panel');
                        if (main) main.style.pointerEvents = 'none';
                    },
                    willClose: () => {
                        document.body.style.overflow = 'auto';
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = 'changePassword.php';
                    }
                });
            }, 500);
        });
    }
</script>