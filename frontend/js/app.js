ymaps.ready(async function () {
  const map = new ymaps.Map("map", {
    center: [55.7558, 37.6176],
    zoom: 13,
    controls: ["zoomControl", "geolocationControl"]
  });

  const res = await fetch("http://127.0.0.1:8000/api/reports");
  const geojson = await res.json();

  geojson.features.forEach(f => {
    if (!f.geometry) return;

    if (f.geometry.type === "Polygon") {
      const ring = f.geometry.coordinates[0].map(([lon, lat]) => [lat, lon]);

      const color =
        f.properties.severity === "red" ? "#ff0000" :
        f.properties.severity === "yellow" ? "#ff9900" :
        "#00aa00";

      const poly = new ymaps.Polygon([ring], {
        balloonContent: `<b>${f.properties.title ?? ""}</b><br>${f.properties.description ?? ""}`
      }, {
        fillColor: color + "55",
        strokeColor: color,
        strokeWidth: 2
      });

      map.geoObjects.add(poly);
    }
  });
});
