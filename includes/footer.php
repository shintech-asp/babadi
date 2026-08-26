<?php
// includes/footer.php
?>
<footer class="main-footer">
    <div class="container">
        <div class="footer-content">
            <div class="footer-section">
                <div class="footer-logo">
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 1rem;">
                        <div style="width: 40px; height: 40px; background: white; border-radius: var(--radius); display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 1.25rem;">
                            <i class="fas fa-bug"></i>
                        </div>
                        <div style="display: flex; flex-direction: column;">
                            <div style="font-size: 1.5rem; font-weight: 800; color: white; line-height: 1;">Pestify</div>
                            <div style="font-size: 0.7rem; color: rgba(255,255,255,0.7); line-height: 1; font-weight: 500; letter-spacing: 0.5px;">Professional Pest Control</div>
                        </div>
                    </div>
                    
                    <p class="footer-description">Your trusted platform for connecting with professional pest control services. Safe, reliable, and effective solutions.</p>
                </div>
                
                <div class="social-links">
                    <a href="#" class="social-link" title="Facebook">
                        <i class="fab fa-facebook-f"></i>
                    </a>
                    <a href="#" class="social-link" title="Twitter">
                        <i class="fab fa-twitter"></i>
                    </a>
                    <a href="#" class="social-link" title="Instagram">
                        <i class="fab fa-instagram"></i>
                    </a>
                    <a href="#" class="social-link" title="LinkedIn">
                        <i class="fab fa-linkedin-in"></i>
                    </a>
                </div>
            </div>
            
            <div class="footer-section">
                <h3>For Homeowners</h3>
                <ul>
                    <li><a href="<?php echo appUrl('listings.php'); ?>">Find Pest Control Services</a></li>
                    <li><a href="<?php echo appUrl('providers.php'); ?>">Browse Companies</a></li>
                    <li><a href="<?php echo appUrl('register.php?type=seeker'); ?>">Sign Up as Homeowner</a></li>
                    <li><a href="<?php echo appUrl('login.php'); ?>">Login to Account</a></li>
                    <li><a href="<?php echo appUrl('my-requests.php'); ?>">My Service Requests</a></li>
                </ul>
            </div>
            
            <div class="footer-section">
                <h3>For Companies</h3>
                <ul>
                    <li><a href="<?php echo appUrl('register.php?type=provider'); ?>">Register Your Company</a></li>
                    <li><a href="<?php echo appUrl('providers-dashboard.php'); ?>">Provider Dashboard</a></li>
                    <li><a href="<?php echo appUrl('services.php'); ?>">Manage Services</a></li>
                    <li><a href="<?php echo appUrl('providers.php'); ?>">View Competitors</a></li>
                    <li><a href="<?php echo appUrl('profile.php'); ?>">Company Profile</a></li>
                </ul>
            </div>
            
            <div class="footer-section">
                <h3>Support</h3>
                <ul>
                    <li><a href="<?php echo appUrl('contact.php'); ?>">Contact Us</a></li>
                    <li><a href="<?php echo appUrl('faq.php'); ?>">Help & FAQ</a></li>
                    <li><a href="<?php echo appUrl('terms.php'); ?>">Terms of Service</a></li>
                    <li><a href="<?php echo appUrl('privacy.php'); ?>">Privacy Policy</a></li>
                    <li><a href="<?php echo appUrl('safety.php'); ?>">Safety Guidelines</a></li>
                </ul>
            </div>
        </div>
        
        <div class="footer-bottom">
            <p>&copy; <?php echo date('Y'); ?> Pestify. All rights reserved. Pest control services are provided by independent professionals.</p>
            <p style="margin-top: 0.5rem; font-size: 0.75rem; color: rgba(255,255,255,0.6);">
                <i class="fas fa-shield-alt"></i> Licensed & Insured Providers | 
                <i class="fas fa-award"></i> Quality Guaranteed | 
                <i class="fas fa-clock"></i> 24/7 Support
            </p>
        </div>
    </div>
</footer>
