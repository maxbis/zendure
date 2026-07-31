document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("[data-open-dialog]").forEach((button) => {
        button.addEventListener("click", () => {
            GraphiteDialog.open(button.dataset.openDialog, { trigger: button });
        });
    });

    document.querySelectorAll("[data-flash]").forEach((button) => {
        button.addEventListener("click", () => {
            const type = button.dataset.flash;
            const messages = {
                success: "The charging schedule was saved and is active.",
                error: "The energy controller could not be reached. Try again.",
                warning: "Tomorrow’s prices are not available yet.",
                info: "Battery estimates use the latest measured capacity."
            };
            GraphiteFlash[type](messages[type]);
        });
    });

    document.querySelectorAll("[data-dialog-flash]").forEach((button) => {
        button.addEventListener("click", () => {
            const dialog = button.closest("dialog");
            GraphiteDialog.close(dialog, "saved");
            GraphiteFlash.success("Schedule updated for 16:00–17:00.");
        });
    });

    const destructiveConfirm = document.querySelector("[data-confirm-delete]");
    if (destructiveConfirm) {
        destructiveConfirm.addEventListener("click", () => {
            const dialog = destructiveConfirm.closest("dialog");
            GraphiteDialog.close(dialog, "deleted");
            GraphiteFlash.success("Automation removed.", { title: "Deleted" });
        });
    }
});

