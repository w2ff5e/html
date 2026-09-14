const account = JSON.parse(localStorage.getItem("syspointSession") || "null");
if (!account) window.location.href = "login.html";


const userLabel = account?.name || account?.email || "User";
document.querySelector("#greeting-name").textContent = userLabel === "User" ? "there" : userLabel;
document.querySelector("#profile-avatar").textContent = userLabel.split(/\s+/).map((part) => part[0]).join("").slice(0, 2).toUpperCase();


const clients = [
    { name: "Teleperformance", branch: "Makati", description: "A business process services partner supporting customer experience and technical operations.", contact: "Maria Santos", additionalContact: "John Cruz", phone: "+63 917 123 4567", additionalPhone: "+63 917 765 4321", lastOrder: "Aug 22, 2026", orders: 48, status: "Active" },
    { name: "Shangri-La Hotels", branch: "Bonifacio Global City", description: "A hospitality client managing premium hotel operations and guest services.", contact: "Liam Reyes", additionalContact: "", phone: "+63 917 234 5678", additionalPhone: "", lastOrder: "Aug 18, 2026", orders: 35, status: "Active" },
    { name: "Keyence Philippines", branch: "Laguna", description: "An industrial automation and sensing technology partner.", contact: "Ana Garcia", additionalContact: "Paolo Tan", phone: "+63 917 345 6789", additionalPhone: "+63 917 876 5432", lastOrder: "Aug 04, 2026", orders: 27, status: "Active" },
    { name: "Gardenia Bakeries", branch: "Binan", description: "A food manufacturing client with regional distribution operations.", contact: "Carlo Dela Cruz", additionalContact: "", phone: "+63 917 456 7890", additionalPhone: "", lastOrder: "Jul 26, 2026", orders: 38, status: "Pending" }
];
const requests = [];
let selectedClient = "";
const clientRows = document.querySelector("#client-rows");
const searchInput = document.querySelector("#search");
const sortFilter = document.querySelector("#sort-filter");
const modal = document.querySelector("#modal");
const detailsModal = document.querySelector("#client-details-modal");
const deleteForm = document.querySelector("#delete-form");
const toast = document.querySelector("#toast");
const notificationButton = document.querySelector("#notification-button");
const notificationBadge = document.querySelector("#notification-badge");
const notificationPanel = document.querySelector("#notification-panel");


function showToast(message) { toast.textContent = message; toast.hidden = false; window.setTimeout(() => { toast.hidden = true; }, 3200); }
function updateStats() {
    document.querySelector("#total-clients").textContent = clients.length;
    document.querySelector("#active-clients").textContent = clients.filter((client) => client.status === "Active").length;
    document.querySelector("#total-orders").textContent = clients.reduce((total, client) => total + Number(client.orders), 0);
    document.querySelector("#open-requests").textContent = requests.length;
    document.querySelector(".request-count").textContent = requests.length ? `(${requests.length})` : "";
}
function renderClients(filter = "") {
    const normalizedFilter = filter.toLowerCase();
    const filteredClients = clients.filter((client) => `${client.name} ${client.branch}`.toLowerCase().includes(normalizedFilter));
    const sortedClients = [...filteredClients].sort((firstClient, secondClient) => {
        if (sortFilter.value === "a-z") return firstClient.name.localeCompare(secondClient.name);
        const firstDate = Date.parse(firstClient.lastOrder) || 0;
        const secondDate = Date.parse(secondClient.lastOrder) || 0;
        return sortFilter.value === "older" ? firstDate - secondDate : secondDate - firstDate;
    });
    clientRows.innerHTML = sortedClients.length ? sortedClients.map((client) => `<tr class="client-row" tabindex="0" data-client="${client.name}"><td><strong class="client-name">${client.name}</strong><span class="client-hint">View details</span></td><td>${client.branch}</td><td>${client.lastOrder}</td><td>${client.orders}</td><td><span class="status ${client.status === "Pending" ? "pending" : ""}">${client.status}</span></td><td><button class="edit-button" type="button" data-delete="${client.name}">Request delete</button></td></tr>`).join("") : `<tr><td class="empty-row" colspan="6">No companies match your search.</td></tr>`;
    clientRows.querySelectorAll(".client-row").forEach((row) => {
        const openDetails = () => showClientDetails(clients.find((client) => client.name === row.dataset.client));
        row.addEventListener("click", (event) => { if (!event.target.closest("[data-delete]")) openDetails(); });
        row.addEventListener("keydown", (event) => { if (event.key === "Enter" || event.key === " ") { event.preventDefault(); openDetails(); } });
    });
    clientRows.querySelectorAll("[data-delete]").forEach((button) => button.addEventListener("click", () => { selectedClient = button.dataset.delete; modal.hidden = false; document.querySelector("#delete-reason").focus(); }));
}
function renderRequests() {
    document.querySelector("#request-list").innerHTML = requests.length ? requests.map((request) => `<article class="request"><div><h3>${request.client}</h3><p>${request.reason}</p></div><div><span class="status pending">Pending review</span><div class="request-date">${request.date}</div></div></article>`).join("") : `<div class="empty-state"><strong>No requests yet</strong>Deletion requests you submit will appear here for tracking.</div>`;
}
function switchView(viewName) {
    document.querySelectorAll(".view").forEach((view) => view.classList.remove("active"));
    document.querySelector(`#${viewName}-view`).classList.add("active");
    document.querySelectorAll(".nav-button").forEach((button) => button.classList.toggle("active", button.dataset.view === viewName));
        if (viewName === "requests") renderRequests();
}
function closeModal() { modal.hidden = true; deleteForm.reset(); }
function showClientDetails(client) {
    if (!client) return;
    document.querySelector("#client-details-title").textContent = client.name;
    document.querySelector("#client-details-description").textContent = client.description || "No description provided.";
    document.querySelector("#client-details-branch").textContent = client.branch;
    document.querySelector("#client-details-contact").textContent = client.contact || "Not provided";
    document.querySelector("#client-details-additional-contact").textContent = client.additionalContact || "Not provided";
    document.querySelector("#client-details-phone").textContent = client.phone || "Not provided";
    document.querySelector("#client-details-additional-phone").textContent = client.additionalPhone || "Not provided";
    document.querySelector("#client-details-order").textContent = client.lastOrder;
    document.querySelector("#client-details-orders").textContent = client.orders;
    document.querySelector("#client-details-status").textContent = client.status;
    detailsModal.hidden = false;
}
function closeDetails() { detailsModal.hidden = true; }


document.querySelectorAll(".nav-button").forEach((button) => button.addEventListener("click", () => switchView(button.dataset.view)));
document.querySelectorAll("[data-close-modal]").forEach((button) => button.addEventListener("click", closeModal));
document.querySelector("[data-close-details]").addEventListener("click", closeDetails);
searchInput.addEventListener("input", (event) => renderClients(event.target.value));
sortFilter.addEventListener("change", () => renderClients(searchInput.value));
document.querySelector("#logout-button").addEventListener("click", () => { localStorage.removeItem("syspointSession"); window.location.href = "login.html"; });
notificationButton.addEventListener("click", () => { notificationPanel.hidden = !notificationPanel.hidden; notificationButton.setAttribute("aria-expanded", String(!notificationPanel.hidden)); });
document.querySelector("#notification-dismiss").addEventListener("click", () => { notificationBadge.hidden = true; notificationPanel.hidden = true; notificationButton.setAttribute("aria-expanded", "false"); });
deleteForm.addEventListener("submit", (event) => { event.preventDefault(); requests.push({ client: selectedClient, reason: document.querySelector("#delete-reason").value, date: "Just now" }); closeModal(); updateStats(); renderRequests(); showToast(`Deletion request for ${selectedClient} submitted.`); });
document.querySelector("#company-form").addEventListener("submit", (event) => { event.preventDefault(); const companyName = document.querySelector("#company-name").value; clients.push({ name: companyName, branch: document.querySelector("#company-branch").value, description: document.querySelector("#company-description").value, contact: document.querySelector("#company-contact").value, additionalContact: document.querySelector("#company-additional-contact").value, phone: document.querySelector("#company-phone").value, additionalPhone: document.querySelector("#company-additional-phone").value, lastOrder: "No orders yet", orders: Number(document.querySelector("#company-orders").value), status: document.querySelector("#company-status").value }); event.target.reset(); updateStats(); renderClients(); switchView("clients"); showToast(`${companyName} was added to your clients.`); });
document.querySelector("#export-button").addEventListener("click", () => { const header = ["Company name", "Branch", "Last order", "Orders", "Status"]; const rows = clients.map((client) => [client.name, client.branch, client.lastOrder, client.orders, client.status]); const csv = [header, ...rows].map((row) => row.map((value) => `"${String(value).replaceAll('"', '""')}"`).join(",")).join("\n"); const link = document.createElement("a"); link.href = URL.createObjectURL(new Blob([csv], { type: "text/csv" })); link.download = "syspoint-clients.csv"; link.click(); URL.revokeObjectURL(link.href); showToast("Client list exported successfully."); });
updateStats();
renderClients();
renderRequests();



