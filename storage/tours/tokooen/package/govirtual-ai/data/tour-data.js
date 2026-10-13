window.GoVirtualTourData = {
    "id": "tokooen",
    "name": "Tokooen",
    "scenes": [
        {
            "id": "node1",
            "name": "Kuekering",
            "displayName": "Kuekering",
            "rawName": "",
            "customNodeId": "kuekering",
            "pan": 0,
            "tilt": 0,
            "fov": 100,
            "tags": [
                "oi",
                "TokoOen"
            ],
            "aliases": [
                "kuekering"
            ]
        },
        {
            "id": "node2",
            "name": "Areamakan",
            "displayName": "Areamakan",
            "rawName": "",
            "customNodeId": "areamakan",
            "pan": 2.35,
            "tilt": -7.93,
            "fov": 100,
            "tags": [
                "TokoOen"
            ],
            "aliases": [
                "areamakan"
            ]
        },
        {
            "id": "node3",
            "name": "Ruangpertemuan",
            "displayName": "Ruangpertemuan",
            "rawName": "",
            "customNodeId": "ruangpertemuan",
            "pan": 0,
            "tilt": 0,
            "fov": 100,
            "tags": [
                "oi",
                "TokoOen"
            ],
            "aliases": [
                "ruangpertemuan"
            ]
        },
        {
            "id": "node4",
            "name": "Area Parkir Belakang",
            "displayName": "Area Parkir Belakang",
            "rawName": "",
            "customNodeId": "-rea-arkir-elakang",
            "pan": 0,
            "tilt": 0,
            "fov": 100,
            "tags": [
                "TokoOen"
            ],
            "aliases": [
                "Area Parkir Belakang"
            ]
        },
        {
            "id": "node5",
            "name": "Pintu Keluar",
            "displayName": "Pintu Keluar",
            "rawName": "",
            "customNodeId": "-intu-eluar",
            "pan": -3.1736,
            "tilt": -0.242334,
            "fov": 150.109,
            "tags": [
                "TokoOen"
            ],
            "aliases": [
                "Pintu Keluar"
            ]
        },
        {
            "id": "node6",
            "name": "Area Merokok",
            "displayName": "Area Merokok",
            "rawName": "",
            "customNodeId": "-rea-erokok",
            "pan": -48.3188,
            "tilt": -0.852187,
            "fov": 150.109,
            "tags": [
                "TokoOen"
            ],
            "aliases": [
                "Area Merokok"
            ]
        },
        {
            "id": "node7",
            "name": "Areaoutdoor",
            "displayName": "Areaoutdoor",
            "rawName": "",
            "customNodeId": "areaoutdoor",
            "pan": 0,
            "tilt": 0,
            "fov": 100,
            "tags": [
                "oi",
                "TokoOen"
            ],
            "aliases": [
                "areaoutdoor"
            ]
        }
    ],
    "hotspots": [
        {
            "scene": "node1",
            "target": "node5",
            "title": "Pintu Keluar",
            "id": "Point01",
            "pan": -251.93,
            "tilt": -3.21
        },
        {
            "scene": "node1",
            "target": "node2",
            "title": "Toko Oen",
            "id": "Point02",
            "pan": -75.86,
            "tilt": -4.84
        },
        {
            "scene": "node2",
            "target": "node1",
            "title": "Toko Oen",
            "id": "Point01",
            "pan": -355.99,
            "tilt": -5.86
        },
        {
            "scene": "node2",
            "target": "node3",
            "title": "Toko Oen Lt. 2",
            "id": "Point02",
            "pan": -123.45,
            "tilt": -16.71
        },
        {
            "scene": "node2",
            "target": "node7",
            "title": "Pintu Keluar Area Merokok",
            "id": "Point03",
            "pan": -87.96,
            "tilt": -5.4
        },
        {
            "scene": "node3",
            "target": "node2",
            "title": "Toko Oen Lt. 1",
            "id": "Point01",
            "pan": -200.6,
            "tilt": -6.75
        },
        {
            "scene": "node4",
            "target": "node6",
            "title": "Area Merokok",
            "id": "Point01",
            "pan": -218.44,
            "tilt": -7.57
        },
        {
            "scene": "node4",
            "target": "node5",
            "title": "Pintu Keluar",
            "id": "Point02",
            "pan": -0.69,
            "tilt": 1.51
        },
        {
            "scene": "node5",
            "target": "node1",
            "title": "Pintu Masuk",
            "id": "Point01",
            "pan": -351.51,
            "tilt": -6.22
        },
        {
            "scene": "node5",
            "target": "node4",
            "title": "Area Parkir Belakang",
            "id": "Point02",
            "pan": -177.46,
            "tilt": -4.63
        },
        {
            "scene": "node6",
            "target": "node4",
            "title": "Area Parkir",
            "id": "Point01",
            "pan": -42.38,
            "tilt": -9.39
        },
        {
            "scene": "node6",
            "target": "node7",
            "title": "Toilet",
            "id": "Point02",
            "pan": -247.81,
            "tilt": -10.59
        },
        {
            "scene": "node7",
            "target": "node2",
            "title": "Pintu Masuk",
            "id": "Point01",
            "pan": -159.15,
            "tilt": -8.63
        },
        {
            "scene": "node7",
            "target": "node6",
            "title": "Area Merokok",
            "id": "Point02",
            "pan": -222.07,
            "tilt": -9
        }
    ],
    "panoExits": {
        "node1": [
            {
                "target": "node5",
                "title": "Pintu Keluar",
                "suffix": "Point01"
            },
            {
                "target": "node2",
                "title": "Toko Oen",
                "suffix": "Point02"
            }
        ],
        "node2": [
            {
                "target": "node1",
                "title": "Toko Oen",
                "suffix": "Point01"
            },
            {
                "target": "node3",
                "title": "Toko Oen Lt. 2",
                "suffix": "Point02"
            },
            {
                "target": "node7",
                "title": "Pintu Keluar Area Merokok",
                "suffix": "Point03"
            }
        ],
        "node3": [
            {
                "target": "node2",
                "title": "Toko Oen Lt. 1",
                "suffix": "Point01"
            }
        ],
        "node4": [
            {
                "target": "node6",
                "title": "Area Merokok",
                "suffix": "Point01"
            },
            {
                "target": "node5",
                "title": "Pintu Keluar",
                "suffix": "Point02"
            }
        ],
        "node5": [
            {
                "target": "node1",
                "title": "Pintu Masuk",
                "suffix": "Point01"
            },
            {
                "target": "node4",
                "title": "Area Parkir Belakang",
                "suffix": "Point02"
            }
        ],
        "node6": [
            {
                "target": "node4",
                "title": "Area Parkir",
                "suffix": "Point01"
            },
            {
                "target": "node7",
                "title": "Toilet",
                "suffix": "Point02"
            }
        ],
        "node7": [
            {
                "target": "node2",
                "title": "Pintu Masuk",
                "suffix": "Point01"
            },
            {
                "target": "node6",
                "title": "Area Merokok",
                "suffix": "Point02"
            }
        ]
    },
    "thumbPrefix": "ht_node_thumb_10_",
    "routes": [],
    "objectInfo": [
        {
            "infoId": 1060,
            "label": "Area Makan",
            "description": "Area makan di Oud en Nieuw by Toko Oen Semarang menyuguhkan nuansa kolonial klasik yang elegan melalui perpaduan furnitur antik dan dekorasi bergaya retro Eropa. Ruangannya luas serta memberikan kenyamanan modern, namun tetap mempertahankan atmosfer autentik bangunan bersejarah.",
            "fragment": "areamakan",
            "link": "tokooen/index.html#areamakan",
            "sceneId": "node2"
        }
    ],
    "video360": [],
    "_sceneRegistry": {
        "node1": {
            "rawName": "Kuekering",
            "displayName": "Kuekering",
            "placeId": "kuekering",
            "placeName": "Kuekering",
            "variant": null,
            "type": "area",
            "confidence": "NEEDS_REVIEW",
            "evidence": "Seed template from AI Generator",
            "shortDescription": "Kuekering merupakan bagian dari kawasan Tokooen di Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung.",
            "description": "Kuekering adalah area di dalam kawasan Tokooen, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu.",
            "highlights": [
                "Titik panorama",
                "Terhubung jalur eksplorasi"
            ],
            "talkingPoints": [
                "Kuekering",
                "jalur eksplorasi",
                "Tokooen"
            ],
            "suggestedQuestions": [
                {
                    "q": "Apa ciri utama Kuekering?",
                    "mode": "fix",
                    "answer": "Kuekering merupakan bagian dari kawasan Tokooen di Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung."
                },
                {
                    "q": "Apa peran Kuekering dalam kunjungan?",
                    "mode": "fix",
                    "answer": "Kuekering adalah area di dalam kawasan Tokooen, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu."
                },
                {
                    "q": "Mengapa titik panorama menarik untuk diperhatikan?",
                    "mode": "fix",
                    "answer": "Detail tersebut menjadi bagian penting dari Kuekering. Kuekering merupakan bagian dari kawasan Tokooen di Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung."
                }
            ],
            "relatedKnowledgeIds": [],
            "contentGrounding": "identity-only",
            "aiHints": {
                "currentLocation": "Anda sekarang berada di Kuekering — kawasan Tokooen.",
                "describeHere": "Kuekering adalah area di dalam kawasan Tokooen, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu.",
                "interestingHere": "Gunakan highlights terverifikasi.",
                "navigation": "Gunakan graph/hotspot hanya untuk navigasi."
            }
        },
        "node2": {
            "rawName": "Areamakan",
            "displayName": "Areamakan",
            "placeId": "areamakan",
            "placeName": "Areamakan",
            "variant": null,
            "type": "area",
            "confidence": "NEEDS_REVIEW",
            "evidence": "Seed template from AI Generator + auto obj mapping",
            "shortDescription": "Areamakan merupakan bagian dari kawasan Tokooen di Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung.",
            "description": "Areamakan adalah area di dalam kawasan Tokooen, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu.",
            "highlights": [
                "Titik dengan objek info",
                "Terhubung jalur eksplorasi"
            ],
            "talkingPoints": [
                "Areamakan",
                "jalur eksplorasi",
                "Tokooen"
            ],
            "suggestedQuestions": [
                {
                    "q": "Apa ciri utama Areamakan?",
                    "mode": "fix",
                    "answer": "Areamakan merupakan bagian dari kawasan Tokooen di Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung."
                },
                {
                    "q": "Apa peran Areamakan dalam kunjungan?",
                    "mode": "fix",
                    "answer": "Areamakan adalah area di dalam kawasan Tokooen, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu."
                },
                {
                    "q": "Mengapa titik dengan objek info menarik untuk diperhatikan?",
                    "mode": "fix",
                    "answer": "Detail tersebut menjadi bagian penting dari Areamakan. Areamakan merupakan bagian dari kawasan Tokooen di Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung."
                }
            ],
            "relatedKnowledgeIds": [
                "webview:objinfo2:1060"
            ],
            "contentGrounding": "direct",
            "aiHints": {
                "currentLocation": "Anda sekarang berada di Areamakan — kawasan Tokooen.",
                "describeHere": "Areamakan adalah area di dalam kawasan Tokooen, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu.",
                "interestingHere": "Gunakan highlights terverifikasi.",
                "navigation": "Gunakan graph/hotspot hanya untuk navigasi."
            }
        },
        "node3": {
            "rawName": "Ruangpertemuan",
            "displayName": "Ruangpertemuan",
            "placeId": "ruangpertemuan",
            "placeName": "Ruangpertemuan",
            "variant": null,
            "type": "area",
            "confidence": "NEEDS_REVIEW",
            "evidence": "Seed template from AI Generator",
            "shortDescription": "Ruangpertemuan merupakan bagian dari kawasan Tokooen di Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung.",
            "description": "Ruangpertemuan adalah area di dalam kawasan Tokooen, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu.",
            "highlights": [
                "Titik panorama",
                "Terhubung jalur eksplorasi"
            ],
            "talkingPoints": [
                "Ruangpertemuan",
                "jalur eksplorasi",
                "Tokooen"
            ],
            "suggestedQuestions": [
                {
                    "q": "Apa ciri utama Ruangpertemuan?",
                    "mode": "fix",
                    "answer": "Ruangpertemuan merupakan bagian dari kawasan Tokooen di Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung."
                },
                {
                    "q": "Apa peran Ruangpertemuan dalam kunjungan?",
                    "mode": "fix",
                    "answer": "Ruangpertemuan adalah area di dalam kawasan Tokooen, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu."
                },
                {
                    "q": "Mengapa titik panorama menarik untuk diperhatikan?",
                    "mode": "fix",
                    "answer": "Detail tersebut menjadi bagian penting dari Ruangpertemuan. Ruangpertemuan merupakan bagian dari kawasan Tokooen di Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung."
                }
            ],
            "relatedKnowledgeIds": [],
            "contentGrounding": "identity-only",
            "aiHints": {
                "currentLocation": "Anda sekarang berada di Ruangpertemuan — kawasan Tokooen.",
                "describeHere": "Ruangpertemuan adalah area di dalam kawasan Tokooen, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu.",
                "interestingHere": "Gunakan highlights terverifikasi.",
                "navigation": "Gunakan graph/hotspot hanya untuk navigasi."
            }
        },
        "node4": {
            "rawName": "Area Parkir Belakang",
            "displayName": "Area Parkir Belakang",
            "placeId": "area-parkir-belakang",
            "placeName": "Area Parkir Belakang",
            "variant": null,
            "type": "area",
            "confidence": "NEEDS_REVIEW",
            "evidence": "Seed template from AI Generator",
            "shortDescription": "Area Parkir Belakang merupakan bagian dari kawasan Tokooen di Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung.",
            "description": "Area Parkir Belakang adalah area di dalam kawasan Tokooen, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu.",
            "highlights": [
                "Titik panorama",
                "Terhubung jalur eksplorasi"
            ],
            "talkingPoints": [
                "Area Parkir Belakang",
                "jalur eksplorasi",
                "Tokooen"
            ],
            "suggestedQuestions": [
                {
                    "q": "Apa ciri utama Area Parkir Belakang?",
                    "mode": "fix",
                    "answer": "Area Parkir Belakang merupakan bagian dari kawasan Tokooen di Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung."
                },
                {
                    "q": "Apa peran Area Parkir Belakang dalam kunjungan?",
                    "mode": "fix",
                    "answer": "Area Parkir Belakang adalah area di dalam kawasan Tokooen, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu."
                },
                {
                    "q": "Mengapa titik panorama menarik untuk diperhatikan?",
                    "mode": "fix",
                    "answer": "Detail tersebut menjadi bagian penting dari Area Parkir Belakang. Area Parkir Belakang merupakan bagian dari kawasan Tokooen di Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung."
                }
            ],
            "relatedKnowledgeIds": [],
            "contentGrounding": "identity-only",
            "aiHints": {
                "currentLocation": "Anda sekarang berada di Area Parkir Belakang — kawasan Tokooen.",
                "describeHere": "Area Parkir Belakang adalah area di dalam kawasan Tokooen, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu.",
                "interestingHere": "Gunakan highlights terverifikasi.",
                "navigation": "Gunakan graph/hotspot hanya untuk navigasi."
            }
        },
        "node5": {
            "rawName": "Pintu Keluar",
            "displayName": "Pintu Keluar",
            "placeId": "pintu-keluar",
            "placeName": "Pintu Keluar",
            "variant": null,
            "type": "area",
            "confidence": "NEEDS_REVIEW",
            "evidence": "Seed template from AI Generator",
            "shortDescription": "Pintu Keluar merupakan bagian dari kawasan Tokooen di Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung.",
            "description": "Pintu Keluar adalah area di dalam kawasan Tokooen, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu.",
            "highlights": [
                "Titik panorama",
                "Terhubung jalur eksplorasi"
            ],
            "talkingPoints": [
                "Pintu Keluar",
                "jalur eksplorasi",
                "Tokooen"
            ],
            "suggestedQuestions": [
                {
                    "q": "Apa ciri utama Pintu Keluar?",
                    "mode": "fix",
                    "answer": "Pintu Keluar merupakan bagian dari kawasan Tokooen di Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung."
                },
                {
                    "q": "Apa peran Pintu Keluar dalam kunjungan?",
                    "mode": "fix",
                    "answer": "Pintu Keluar adalah area di dalam kawasan Tokooen, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu."
                },
                {
                    "q": "Mengapa titik panorama menarik untuk diperhatikan?",
                    "mode": "fix",
                    "answer": "Detail tersebut menjadi bagian penting dari Pintu Keluar. Pintu Keluar merupakan bagian dari kawasan Tokooen di Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung."
                }
            ],
            "relatedKnowledgeIds": [],
            "contentGrounding": "identity-only",
            "aiHints": {
                "currentLocation": "Anda sekarang berada di Pintu Keluar — kawasan Tokooen.",
                "describeHere": "Pintu Keluar adalah area di dalam kawasan Tokooen, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu.",
                "interestingHere": "Gunakan highlights terverifikasi.",
                "navigation": "Gunakan graph/hotspot hanya untuk navigasi."
            }
        },
        "node6": {
            "rawName": "Area Merokok",
            "displayName": "Area Merokok",
            "placeId": "area-merokok",
            "placeName": "Area Merokok",
            "variant": null,
            "type": "area",
            "confidence": "NEEDS_REVIEW",
            "evidence": "Seed template from AI Generator",
            "shortDescription": "Area Merokok merupakan bagian dari kawasan Tokooen di Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung.",
            "description": "Area Merokok adalah area di dalam kawasan Tokooen, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu.",
            "highlights": [
                "Titik panorama",
                "Terhubung jalur eksplorasi"
            ],
            "talkingPoints": [
                "Area Merokok",
                "jalur eksplorasi",
                "Tokooen"
            ],
            "suggestedQuestions": [
                {
                    "q": "Apa ciri utama Area Merokok?",
                    "mode": "fix",
                    "answer": "Area Merokok merupakan bagian dari kawasan Tokooen di Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung."
                },
                {
                    "q": "Apa peran Area Merokok dalam kunjungan?",
                    "mode": "fix",
                    "answer": "Area Merokok adalah area di dalam kawasan Tokooen, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu."
                },
                {
                    "q": "Mengapa titik panorama menarik untuk diperhatikan?",
                    "mode": "fix",
                    "answer": "Detail tersebut menjadi bagian penting dari Area Merokok. Area Merokok merupakan bagian dari kawasan Tokooen di Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung."
                }
            ],
            "relatedKnowledgeIds": [],
            "contentGrounding": "identity-only",
            "aiHints": {
                "currentLocation": "Anda sekarang berada di Area Merokok — kawasan Tokooen.",
                "describeHere": "Area Merokok adalah area di dalam kawasan Tokooen, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu.",
                "interestingHere": "Gunakan highlights terverifikasi.",
                "navigation": "Gunakan graph/hotspot hanya untuk navigasi."
            }
        },
        "node7": {
            "rawName": "Areaoutdoor",
            "displayName": "Areaoutdoor",
            "placeId": "areaoutdoor",
            "placeName": "Areaoutdoor",
            "variant": null,
            "type": "area",
            "confidence": "NEEDS_REVIEW",
            "evidence": "Seed template from AI Generator",
            "shortDescription": "Areaoutdoor merupakan bagian dari kawasan Tokooen di Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung.",
            "description": "Areaoutdoor adalah area di dalam kawasan Tokooen, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu.",
            "highlights": [
                "Titik panorama",
                "Terhubung jalur eksplorasi"
            ],
            "talkingPoints": [
                "Areaoutdoor",
                "jalur eksplorasi",
                "Tokooen"
            ],
            "suggestedQuestions": [
                {
                    "q": "Apa ciri utama Areaoutdoor?",
                    "mode": "fix",
                    "answer": "Areaoutdoor merupakan bagian dari kawasan Tokooen di Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung."
                },
                {
                    "q": "Apa peran Areaoutdoor dalam kunjungan?",
                    "mode": "fix",
                    "answer": "Areaoutdoor adalah area di dalam kawasan Tokooen, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu."
                },
                {
                    "q": "Mengapa titik panorama menarik untuk diperhatikan?",
                    "mode": "fix",
                    "answer": "Detail tersebut menjadi bagian penting dari Areaoutdoor. Areaoutdoor merupakan bagian dari kawasan Tokooen di Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung."
                }
            ],
            "relatedKnowledgeIds": [],
            "contentGrounding": "identity-only",
            "aiHints": {
                "currentLocation": "Anda sekarang berada di Areaoutdoor — kawasan Tokooen.",
                "describeHere": "Areaoutdoor adalah area di dalam kawasan Tokooen, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu.",
                "interestingHere": "Gunakan highlights terverifikasi.",
                "navigation": "Gunakan graph/hotspot hanya untuk navigasi."
            }
        }
    },
    "modernObjectInfo": {
        "1479": {
            "name": {
                "id": "Ruang Pertemuan"
            },
            "image": "ruang-pertemuan-ruangpertemuan.webp"
        },
        "1480": {
            "name": {
                "id": "Kue Kering"
            },
            "image": "kue-kering-kuekering.webp"
        },
        "1481": {
            "name": {
                "id": "Area Outdoor"
            },
            "image": "area-outdoor-areaoutdoor.webp"
        }
    }
};
