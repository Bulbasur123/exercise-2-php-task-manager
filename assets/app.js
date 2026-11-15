/* -------------------------------------------------------------------------
   TaskFlow — Exercise #2
   Frontend interactions: add, complete, delete, filter, and search.
   ------------------------------------------------------------------------- */

(() => {
  "use strict";

  const CHECK_ICON = `
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
      stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="m5 12 4 4L19 6" />
    </svg>`;

  const TRASH_ICON = `
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
      stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M4 7h16M9 7V4.8c0-.5.4-.8.8-.8h4.4c.4 0 .8.3.8.8V7M6.5 7l.8 12.1c0 .5.4.9.9.9h7.6c.5 0 .9-.4.9-.9L17.5 7M10 11v5.5M14 11v5.5" />
    </svg>`;

  const CALENDAR_ICON = `
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
      stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <rect x="4" y="5.5" width="16" height="15" rx="3" />
      <path d="M8 3.5v4M16 3.5v4M4 10h16" />
    </svg>`;

  const stateNode = document.getElementById("initial-state");
  let initialState = { tasks: [], stats: { total: 0, done: 0, active: 0, percent: 0 } };

  try {
    if (stateNode && stateNode.textContent) {
      initialState = JSON.parse(stateNode.textContent);
    }
  } catch (error) {
    console.error("Could not read the initial task state.", error);
  }

  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || "";
  const taskList = document.getElementById("task-list");
  const taskForm = document.getElementById("task-form");
  const titleInput = document.getElementById("task-title");
  const priorityInput = document.getElementById("task-priority");
  const dueInput = document.getElementById("task-due");
  const addButton = document.getElementById("add-button");
  const addButtonLabel = addButton?.querySelector("span");
  const searchInput = document.getElementById("search-tasks");
  const clearButton = document.getElementById("clear-completed");
  const filterButtons = Array.from(document.querySelectorAll("[data-filter]"));
  const progressRing = document.getElementById("progress-ring");
  const progressValue = document.getElementById("progress-value");
  const statTotal = document.getElementById("stat-total");
  const statActive = document.getElementById("stat-active");
  const statDone = document.getElementById("stat-done");
  const emptyState = document.getElementById("empty-state");
  const emptyTitle = document.getElementById("empty-title");
  const emptyText = document.getElementById("empty-text");
  const listFootnote = document.getElementById("list-footnote");
  const toast = document.getElementById("toast");
  const toastMessage = document.getElementById("toast-message");

  const countNodes = {
    all: document.querySelector('[data-count="all"]'),
    active: document.querySelector('[data-count="active"]'),
    done: document.querySelector('[data-count="done"]'),
  };

  let tasks = Array.isArray(initialState.tasks) ? initialState.tasks : [];
  let stats = initialState.stats || { total: 0, done: 0, active: 0, percent: 0 };
  let activeFilter = "all";
  let searchQuery = "";
  let toastTimer = null;

  function capitalize(value) {
    if (typeof value !== "string" || value.length === 0) {
      return "Medium";
    }
    return value.charAt(0).toUpperCase() + value.slice(1);
  }

  function parseISODate(value) {
    if (typeof value !== "string" || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
      return null;
    }

    const [year, month, day] = value.split("-").map(Number);
    const date = new Date(year, month - 1, day, 12, 0, 0, 0);
    return Number.isNaN(date.getTime()) ? null : date;
  }

  function startOfDay(value) {
    return new Date(value.getFullYear(), value.getMonth(), value.getDate());
  }

  function dueInfo(value, done) {
    const date = parseISODate(value);
    if (!date) {
      return null;
    }

    const today = startOfDay(new Date());
    const target = startOfDay(date);
    const dayDifference = Math.round((target.getTime() - today.getTime()) / 86400000);
    let label;

    if (dayDifference === 0) {
      label = "Today";
    } else if (dayDifference === 1) {
      label = "Tomorrow";
    } else if (dayDifference === -1) {
      label = "Yesterday";
    } else {
      label = new Intl.DateTimeFormat(undefined, {
        month: "short",
        day: "numeric",
      }).format(date);
    }

    return {
      label,
      overdue: !done && dayDifference < 0,
    };
  }

  function createTaskElement(task) {
    const item = document.createElement("li");
    item.className = `task${task.done ? " is-done" : ""}`;
    item.dataset.id = task.id;

    const checkButton = document.createElement("button");
    checkButton.className = "task-check";
    checkButton.type = "button";
    checkButton.setAttribute("role", "checkbox");
    checkButton.setAttribute("aria-checked", task.done ? "true" : "false");
    checkButton.setAttribute(
      "aria-label",
      `${task.done ? "Mark as not done" : "Mark as done"}: ${task.title}`
    );
    checkButton.innerHTML = CHECK_ICON;

    const copy = document.createElement("div");
    copy.className = "task-copy";

    const title = document.createElement("p");
    title.className = "task-title";
    title.textContent = task.title;

    const meta = document.createElement("div");
    meta.className = "task-meta";

    const priority = document.createElement("span");
    const priorityName = ["low", "medium", "high"].includes(task.priority)
      ? task.priority
      : "medium";
    priority.className = `priority priority-${priorityName}`;

    const priorityDot = document.createElement("span");
    priorityDot.className = "priority-dot";
    priority.append(priorityDot, document.createTextNode(capitalize(priorityName)));

    meta.append(priority);

    const due = dueInfo(task.due, task.done);
    if (due) {
      const dueNode = document.createElement("span");
      dueNode.className = `due${due.overdue ? " is-overdue" : ""}`;
      dueNode.innerHTML = CALENDAR_ICON;
      dueNode.append(document.createTextNode(due.label));
      meta.append(dueNode);
    }

    copy.append(title, meta);

    const deleteButton = document.createElement("button");
    deleteButton.className = "task-delete";
    deleteButton.type = "button";
    deleteButton.setAttribute("aria-label", `Delete task: ${task.title}`);
    deleteButton.innerHTML = TRASH_ICON;

    item.append(checkButton, copy, deleteButton);
    return item;
  }

  function updateProgress() {
    const percent = Number.isFinite(Number(stats.percent)) ? Number(stats.percent) : 0;

    if (progressRing) {
      progressRing.style.setProperty("--progress", `${percent}%`);
      progressRing.setAttribute("aria-label", `${percent}% of tasks complete`);
    }
    if (progressValue) {
      progressValue.textContent = `${percent}%`;
    }
    if (statTotal) {
      statTotal.textContent = String(stats.total);
    }
    if (statActive) {
      statActive.textContent = String(stats.active);
    }
    if (statDone) {
      statDone.textContent = String(stats.done);
    }
    if (countNodes.all) {
      countNodes.all.textContent = String(stats.total);
    }
    if (countNodes.active) {
      countNodes.active.textContent = String(stats.active);
    }
    if (countNodes.done) {
      countNodes.done.textContent = String(stats.done);
    }
    if (clearButton) {
      clearButton.disabled = stats.done === 0;
    }
  }

  function updateFilterButtons() {
    filterButtons.forEach((button) => {
      const isActive = button.dataset.filter === activeFilter;
      button.classList.toggle("is-active", isActive);
      button.setAttribute("aria-pressed", isActive ? "true" : "false");
    });
  }

  function updateEmptyState(visibleCount) {
    if (!emptyState || !emptyTitle || !emptyText) {
      return;
    }

    const hasSearch = searchQuery.trim() !== "";
    emptyState.hidden = visibleCount > 0;

    if (hasSearch) {
      emptyTitle.textContent = "No matches.";
      emptyText.textContent = "Try a different word, or clear the search to see everything.";
      return;
    }

    if (activeFilter === "done") {
      emptyTitle.textContent = "Nothing checked off yet.";
      emptyText.textContent = "Finished tasks will collect here once you start crossing things out.";
      return;
    }

    if (activeFilter === "active") {
      emptyTitle.textContent = "All clear.";
      emptyText.textContent = "Every task is done. Add something new, or enjoy the quiet.";
      return;
    }

    emptyTitle.textContent = "A clear board.";
    emptyText.textContent = "Add your first task on the left and it will show up here.";
  }

  function updateFootnote(visibleCount) {
    if (!listFootnote) {
      return;
    }

    const query = searchQuery.trim();
    if (query !== "") {
      listFootnote.textContent = `Showing ${visibleCount} of ${stats.total} task${stats.total === 1 ? "" : "s"} for “${query}”.`;
      return;
    }

    if (activeFilter === "active") {
      listFootnote.textContent = `Showing ${visibleCount} open task${visibleCount === 1 ? "" : "s"}. Changes save automatically.`;
      return;
    }

    if (activeFilter === "done") {
      listFootnote.textContent = `Showing ${visibleCount} completed task${visibleCount === 1 ? "" : "s"}. Changes save automatically.`;
      return;
    }

    if (stats.total === 0) {
      listFootnote.textContent = "Nothing here yet. Add a task to get started.";
      return;
    }

    listFootnote.textContent = `${stats.active} open · ${stats.done} done. Changes save automatically.`;
  }

  function render() {
    if (!taskList) {
      return;
    }

    const query = searchQuery.trim().toLowerCase();
    const visible = tasks.filter((task) => {
      const matchesFilter =
        activeFilter === "all" ||
        (activeFilter === "active" && !task.done) ||
        (activeFilter === "done" && task.done);
      const matchesSearch = query === "" || task.title.toLowerCase().includes(query);
      return matchesFilter && matchesSearch;
    });

    taskList.replaceChildren(...visible.map(createTaskElement));
    updateProgress();
    updateFilterButtons();
    updateEmptyState(visible.length);
    updateFootnote(visible.length);
  }

  function showToast(message, type = "success") {
    if (!toast || !toastMessage) {
      return;
    }

    toastMessage.textContent = message;
    toast.classList.toggle("is-error", type === "error");
    toast.classList.add("is-visible");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
      toast.classList.remove("is-visible");
    }, 2800);
  }

  function errorMessage(error) {
    return error instanceof Error && error.message
      ? error.message
      : "Something went wrong. Please try again.";
  }

  async function sendAction(action, payload = {}) {
    const response = await fetch("api.php", {
      method: "POST",
      headers: {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "X-CSRF-Token": csrfToken,
        "X-Requested-With": "XMLHttpRequest",
      },
      body: JSON.stringify({ action, ...payload }),
    });

    let data;
    try {
      data = await response.json();
    } catch (error) {
      throw new Error("The server returned an unexpected response.");
    }

    if (!response.ok || !data.ok) {
      throw new Error(data.error || "That action could not be completed.");
    }

    if (Array.isArray(data.tasks)) {
      tasks = data.tasks;
    }
    if (data.stats) {
      stats = data.stats;
    }

    render();

    if (data.message) {
      showToast(data.message);
    }

    return data;
  }

  async function toggleTask(id, button) {
    const item = button.closest(".task");
    item?.classList.add("is-busy");

    try {
      await sendAction("toggle", { id });
    } catch (error) {
      showToast(errorMessage(error), "error");
      item?.classList.remove("is-busy");
    }
  }

  async function deleteTask(id, button) {
    const item = button.closest(".task");
    item?.classList.add("is-busy");

    try {
      await sendAction("delete", { id });
    } catch (error) {
      showToast(errorMessage(error), "error");
      item?.classList.remove("is-busy");
    }
  }

  taskForm?.addEventListener("submit", async (event) => {
    event.preventDefault();

    const title = titleInput.value.trim();
    if (title === "") {
      titleInput.reportValidity();
      return;
    }

    addButton.disabled = true;
    if (addButtonLabel) {
      addButtonLabel.textContent = "Adding…";
    }

    try {
      const data = await sendAction("add", {
        title,
        priority: priorityInput.value,
        due: dueInput.value,
      });

      taskForm.reset();
      activeFilter = "all";
      searchQuery = "";
      searchInput.value = "";
      updateFilterButtons();
      render();
      titleInput.focus();

      if (data.created_id) {
        const newTask = taskList.querySelector(`[data-id="${data.created_id}"]`);
        if (newTask) {
          newTask.classList.add("is-new");
          newTask.scrollIntoView({ behavior: "smooth", block: "nearest" });
          setTimeout(() => newTask.classList.remove("is-new"), 1600);
        }
      }
    } catch (error) {
      showToast(errorMessage(error), "error");
    } finally {
      addButton.disabled = false;
      if (addButtonLabel) {
        addButtonLabel.textContent = "Add task";
      }
    }
  });

  taskList?.addEventListener("click", (event) => {
    const button = event.target.closest("button");
    if (!button) {
      return;
    }

    const item = button.closest(".task");
    if (!item || !item.dataset.id) {
      return;
    }

    if (button.classList.contains("task-check")) {
      toggleTask(item.dataset.id, button);
    } else if (button.classList.contains("task-delete")) {
      deleteTask(item.dataset.id, button);
    }
  });

  filterButtons.forEach((button) => {
    button.addEventListener("click", () => {
      activeFilter = button.dataset.filter || "all";
      render();
    });
  });

  searchInput?.addEventListener("input", () => {
    searchQuery = searchInput.value;
    render();
  });

  searchInput?.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      searchInput.value = "";
      searchQuery = "";
      render();
    }
  });

  clearButton?.addEventListener("click", async () => {
    if (stats.done === 0) {
      return;
    }

    const label = `Remove ${stats.done} completed task${stats.done === 1 ? "" : "s"}?`;
    if (!window.confirm(label)) {
      return;
    }

    clearButton.disabled = true;

    try {
      await sendAction("clear_completed");
    } catch (error) {
      showToast(errorMessage(error), "error");
      clearButton.disabled = stats.done === 0;
    }
  });

  render();
})();
