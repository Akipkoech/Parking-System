function validateRegisterForm() {
    const name = document.getElementById('name').value.trim();
    const email = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    if (!name) {
        alert('Name is required');
        return false;
    }
    if (!emailRegex.test(email)) {
        alert('Please enter a valid email address');
        return false;
    }
    if (password.length < 6) {
        alert('Password must be at least 6 characters');
        return false;
    }
    if (password !== confirmPassword) {
        alert('Passwords do not match');
        return false;
    }
    return true;
}

// Fetch and display parking slots (for both user and admin dashboards)
function fetchSlots() {
    const slotsContainer = document.getElementById('slots-container');
    const ajaxError = document.getElementById('ajax-error');
    slotsContainer.innerHTML = '<p class="text-gray-500">Loading slots...</p>';
    ajaxError.classList.add('hidden');

    fetch('./admin_dashboard.php?get_slots=true')
        .then(response => response.text())
        .then(html => {
            slotsContainer.innerHTML = html;
            setupSlotAdminActions(); // Re-bind buttons after reload
        })
        .catch(error => {
            ajaxError.textContent = `Failed to load slots: ${error.message}`;
            ajaxError.classList.remove('hidden');
            slotsContainer.innerHTML = '<p class="text-gray-500">Error loading slots.</p>';
        });
}

function setupSlotAdminActions() {
    // Delete
    document.querySelectorAll('.delete-slot-btn').forEach(btn => {
        btn.onclick = () => {
            const slotId = btn.getAttribute('data-slot-id');
            if (confirm('Delete this slot?')) {
                const formData = new FormData();
                formData.append('slot_id', slotId);
                formData.append('delete_slot', 'true');
                fetch('./admin_dashboard.php', { method: 'POST', body: formData })
                    .then(() => fetchSlots());
            }
        };
    });
    // Enable/Disable
    document.querySelectorAll('.toggle-slot-btn').forEach(btn => {
        btn.onclick = () => {
            const slotId = btn.getAttribute('data-slot-id');
            const currentStatus = btn.getAttribute('data-current-status');
            const newStatus = currentStatus === 'disabled' ? 'available' : 'disabled';
            const formData = new FormData();
            formData.append('slot_id', slotId);
            formData.append('new_status', newStatus);
            formData.append('toggle_slot_status', 'true');
            fetch('./admin_dashboard.php', { method: 'POST', body: formData })
                .then(() => fetchSlots());
        };
    });
}

// Slot creation (AJAX)
function setupCreateSlot() {
    const createForm = document.querySelector('#slots-section form');
    if (createForm) {
        createForm.onsubmit = (e) => {
            e.preventDefault();
            const formData = new FormData(createForm);
            formData.append('add_slot', 'true');
            fetch('./admin_dashboard.php', { method: 'POST', body: formData })
                .then(() => {
                    fetchSlots();
                    createForm.reset();
                });
        };
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    console.log('DOM fully loaded and script running!');

    const toggleBtn = document.getElementById('toggle-slots-btn');
    const slotsSection = document.getElementById('slots-section');
    const slotsContainer = document.getElementById('slots-container');
    const toggleIcon = document.getElementById('toggle-icon');
    const labelSpan = toggleBtn ? toggleBtn.querySelector('span') : null;

    if (toggleBtn && slotsSection && slotsContainer && toggleIcon && labelSpan) {
        console.log('Toggle button and slots section found.');
        toggleBtn.addEventListener('click', function() {
            console.log('Toggle button clicked!');
            const isHidden = slotsSection.classList.contains('hidden');
            if (isHidden) {
                slotsSection.classList.remove('hidden');
                labelSpan.textContent = 'Hide Available Parking Slots';
                toggleIcon.style.transform = 'rotate(180deg)';
                slotsContainer.innerHTML = '<p class="text-gray-500">Loading slots...</p>';
                fetch('dashboard.php?get_slots=true')
                    .then(response => response.text())
                    .then(html => {
                        slotsContainer.innerHTML = html;
                        console.log('Slots loaded!');
                    })
                    .catch(() => {
                        slotsContainer.innerHTML = '<p class="text-red-500">Failed to load slots.</p>';
                        console.log('Failed to load slots.');
                    });
            } else {
                slotsSection.classList.add('hidden');
                labelSpan.textContent = 'Show Available Parking Slots';
                toggleIcon.style.transform = 'rotate(0deg)';
            }
        });
    } else {
        console.log('Toggle button or slots section not found!');
    }

    // Populate slot select options
    const slotSelect = document.getElementById('slot_id');
    if (slotSelect) {
        fetch('dashboard.php?get_slots_select=true')
            .then(response => response.text())
            .then(optionsHtml => {
                slotSelect.innerHTML = '<option value="">Choose a slot</option>' + optionsHtml;
            })
            .catch(() => {
                slotSelect.innerHTML = '<option value="">Failed to load slots</option>';
            });
    }
});