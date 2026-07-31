document.addEventListener("DOMContentLoaded", () => {
    const hourButtons = document.querySelectorAll("[data-mock-hour]");
    const currentHour = document.querySelector(".mock-hour.is-current");
    const priceScroller = currentHour?.closest(".mock-chart-scroll");
    const energyChart = document.querySelector(".mock-energy-chart");
    const energyScroller = energyChart?.closest(".mock-chart-scroll");
    const dialog = document.getElementById("mock-price-dialog");
    const title = dialog?.querySelector("[data-mock-slot-title]");
    const consumerPrice = dialog?.querySelector("[data-mock-consumer-price]");
    const spotPrice = dialog?.querySelector("[data-mock-spot-price]");
    const schedule = dialog?.querySelector("[data-mock-schedule]");

    if (currentHour && priceScroller) {
        priceScroller.scrollLeft = Math.max(
            0,
            currentHour.offsetLeft - ((priceScroller.clientWidth - currentHour.offsetWidth) / 2)
        );
    }

    if (energyChart && energyScroller) {
        energyScroller.scrollLeft = energyChart.scrollWidth - energyScroller.clientWidth;
    }

    hourButtons.forEach((button) => {
        button.addEventListener("click", () => {
            if (!dialog) return;
            title.textContent = `${button.dataset.hour}:00–${String(Number(button.dataset.hour) + 1).padStart(2, "0")}:00`;
            consumerPrice.textContent = button.dataset.price;
            spotPrice.textContent = button.dataset.spot;
            schedule.textContent = button.dataset.schedule;
            GraphiteDialog.open(dialog, { trigger: button });
        });
    });

    document.querySelector("[data-mock-refresh]")?.addEventListener("click", () => {
        GraphiteFlash.info("This static mockup is showing representative data.", {
            title: "Preview refreshed",
            duration: 3500
        });
    });

    document.querySelector("[data-mock-auto]")?.addEventListener("click", () => {
        GraphiteDialog.close(dialog, "auto");
        GraphiteFlash.success("Automatic scheduling selected for this preview.");
    });

    document.querySelector("[data-mock-edit]")?.addEventListener("click", () => {
        GraphiteDialog.close(dialog, "edit");
        GraphiteFlash.info("Editing is intentionally not connected in this mockup.", {
            title: "Static preview"
        });
    });
});
