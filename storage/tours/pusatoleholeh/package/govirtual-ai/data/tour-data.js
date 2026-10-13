window.GoVirtualTourData = {
    "id": "pusatoleholeh",
    "name": "Pusatoleholeh",
    "scenes": [
        {
            "id": "node81",
            "name": "Lorong1",
            "displayName": "Lorong1",
            "rawName": "",
            "customNodeId": "lorong1",
            "pan": 0,
            "tilt": 0,
            "fov": 100,
            "tags": [
                "oi",
                "pusatoleholeh"
            ],
            "aliases": [
                "lorong1"
            ]
        },
        {
            "id": "node82",
            "name": "Etalase",
            "displayName": "Etalase",
            "rawName": "",
            "customNodeId": "-talase",
            "pan": 0,
            "tilt": 0,
            "fov": 130,
            "tags": [
                "pusatoleholeh"
            ],
            "aliases": [
                "Etalase"
            ]
        },
        {
            "id": "node83",
            "name": "Lorong2",
            "displayName": "Lorong2",
            "rawName": "",
            "customNodeId": "lorong2",
            "pan": 0,
            "tilt": 0,
            "fov": 100,
            "tags": [
                "oi",
                "pusatoleholeh"
            ],
            "aliases": [
                "lorong2"
            ]
        },
        {
            "id": "node84",
            "name": "Areadepan",
            "displayName": "Areadepan",
            "rawName": "",
            "customNodeId": "areadepan",
            "pan": 0,
            "tilt": 0,
            "fov": 130,
            "tags": [
                "oi",
                "pusatoleholeh"
            ],
            "aliases": [
                "areadepan"
            ]
        },
        {
            "id": "node85",
            "name": "Areatengah",
            "displayName": "Areatengah",
            "rawName": "",
            "customNodeId": "areatengah",
            "pan": 0,
            "tilt": 0,
            "fov": 100,
            "tags": [
                "oi",
                "pusatoleholeh"
            ],
            "aliases": [
                "areatengah"
            ]
        },
        {
            "id": "node86",
            "name": "Halte",
            "displayName": "Halte",
            "rawName": "",
            "customNodeId": "halte",
            "pan": 0.878389,
            "tilt": 8.59437,
            "fov": 126.563,
            "tags": [
                "oi",
                "pusatoleholeh"
            ],
            "aliases": [
                "halte"
            ]
        }
    ],
    "hotspots": [
        {
            "scene": "node81",
            "target": "node82",
            "title": "Etalase",
            "id": "Point01",
            "pan": -215.32,
            "tilt": -17
        },
        {
            "scene": "node82",
            "target": "node85",
            "title": "Omah Loenpia Djoe",
            "id": "Point01",
            "pan": -180.96,
            "tilt": -13.07
        },
        {
            "scene": "node82",
            "target": "node81",
            "title": "Etalase",
            "id": "Point02",
            "pan": -49.97,
            "tilt": -17.19
        },
        {
            "scene": "node83",
            "target": "node85",
            "title": "Omah Loenpia Djoe",
            "id": "Point01",
            "pan": -2.90001,
            "tilt": -7.5
        },
        {
            "scene": "node83",
            "target": "node84",
            "title": "Pintu Masuk",
            "id": "Point02",
            "pan": -179.58,
            "tilt": -12.73
        },
        {
            "scene": "node84",
            "target": "node86",
            "title": "Pintu Masuk",
            "id": "Point02",
            "pan": 197.83,
            "tilt": -15.83
        },
        {
            "scene": "node84",
            "target": "node83",
            "title": "Etalase",
            "id": "Point01",
            "pan": -348.16,
            "tilt": -4.07
        },
        {
            "scene": "node84",
            "target": "node85",
            "title": "Omah Loenpia Djoe",
            "id": "Point03",
            "pan": -22.89,
            "tilt": -5.41
        },
        {
            "scene": "node85",
            "target": "node86",
            "title": "Kasir",
            "id": "Point01",
            "pan": -93.31,
            "tilt": -1.48
        },
        {
            "scene": "node85",
            "target": "node83",
            "title": "Etalase",
            "id": "Point02",
            "pan": -176.07,
            "tilt": -21.49
        },
        {
            "scene": "node85",
            "target": "node82",
            "title": "Etalase",
            "id": "Point04",
            "pan": -274.98,
            "tilt": -15.5
        },
        {
            "scene": "node86",
            "target": "node84",
            "title": "Pintu Masuk",
            "id": "Point01",
            "pan": 11.83,
            "tilt": -8.58
        }
    ],
    "panoExits": {
        "node81": [
            {
                "target": "node82",
                "title": "Etalase",
                "suffix": "Point01"
            }
        ],
        "node82": [
            {
                "target": "node85",
                "title": "Omah Loenpia Djoe",
                "suffix": "Point01"
            },
            {
                "target": "node81",
                "title": "Etalase",
                "suffix": "Point02"
            }
        ],
        "node83": [
            {
                "target": "node85",
                "title": "Omah Loenpia Djoe",
                "suffix": "Point01"
            },
            {
                "target": "node84",
                "title": "Pintu Masuk",
                "suffix": "Point02"
            }
        ],
        "node84": [
            {
                "target": "node86",
                "title": "Pintu Masuk",
                "suffix": "Point02"
            },
            {
                "target": "node83",
                "title": "Etalase",
                "suffix": "Point01"
            },
            {
                "target": "node85",
                "title": "Omah Loenpia Djoe",
                "suffix": "Point03"
            }
        ],
        "node85": [
            {
                "target": "node86",
                "title": "Kasir",
                "suffix": "Point01"
            },
            {
                "target": "node83",
                "title": "Etalase",
                "suffix": "Point02"
            },
            {
                "target": "node82",
                "title": "Etalase",
                "suffix": "Point04"
            }
        ],
        "node86": [
            {
                "target": "node84",
                "title": "Pintu Masuk",
                "suffix": "Point01"
            }
        ]
    },
    "thumbPrefix": "ht_node_thumb_10_",
    "routes": [],
    "objectInfo": [
        {
            "infoId": 1168,
            "label": "Area Depan",
            "description": "Bagian depan berfungsi sebagai zona penyambutan sekaligus area pajangan di mana pengunjung bisa melihat berbagai varian loenpia segar yang siap dipesan. Lokasi ini didesain lebih terbuka agar memudahkan akses bagi pembeli yang ingin membawa pulang oleh-oleh dengan cepat dan praktis.",
            "fragment": "areadepan",
            "link": "pusatoleholeh/index.html#areadepan",
            "sceneId": "node84"
        },
        {
            "infoId": 1169,
            "label": "Area Tengah",
            "description": "Area ini merupakan ruang utama yang menghubungkan beberapa etalase produk, akses lantai 2, dan tulisan autentik serta area tunggu bagi pengunjung yang datang.",
            "fragment": "areatengah",
            "link": "pusatoleholeh/index.html#areatengah",
            "sceneId": "node85"
        }
    ],
    "video360": [],
    "_sceneRegistry": {
        "node81": {
            "rawName": "Lorong1",
            "displayName": "Lorong Produk – Sisi Pendingin",
            "placeId": "pusat-oleh-oleh-lorong-produk-sisi-pendingin",
            "placeName": "Pusat Oleh-Oleh – Lorong Produk – Sisi Pendingin",
            "variant": null,
            "type": "area",
            "confidence": "NEEDS_REVIEW",
            "evidence": "Bulk import via CSV/XLSX",
            "shortDescription": "Lorong Produk – Sisi Pendingin di Pusat Oleh-Oleh memperlihatkan lemari pendingin dan rak.",
            "description": "Lorong Produk – Sisi Pendingin di Pusat Oleh-Oleh memperlihatkan lemari pendingin dan rak. Area belanja menampilkan barang melalui rak, etalase, atau konter. Susunan pajangan membantu pengunjung membandingkan pilihan sebelum bertransaksi. Rak memanfaatkan ruang vertikal dan membagi benda dalam kelompok yang lebih mudah dilihat. Susunannya membantu pencarian serta penataan kembali setelah digunakan.",
            "highlights": [
                "Lemari pendingin",
                "Rak"
            ],
            "talkingPoints": [
                "Lorong1",
                "jalur eksplorasi",
                "Pusatoleholeh"
            ],
            "suggestedQuestions": [
                {
                    "q": "Apa fungsi utama Lorong Produk – Sisi Pendingin di kawasan ini?",
                    "mode": "fix",
                    "answer": "Lorong Produk – Sisi Pendingin di Pusat Oleh-Oleh memperlihatkan lemari pendingin dan rak."
                },
                {
                    "q": "Bagian mana yang paling membantu memahami fungsi Lorong Produk – Sisi Pendingin?",
                    "mode": "fix",
                    "answer": "Lemari pendingin dan Rak memperjelas susunan serta penggunaan ruang. Lorong Produk – Sisi Pendingin di Pusat Oleh-Oleh memperlihatkan lemari pendingin dan rak."
                },
                {
                    "q": "Apa fakta penting tentang Pusat Oleh-Oleh – Lorong Produk – Sisi Pendingin yang berkaitan dengan sudut ini?",
                    "mode": "fix",
                    "answer": "Bagian depan berfungsi sebagai zona penyambutan sekaligus area pajangan di mana pengunjung bisa melihat berbagai varian loenpia segar yang siap dipesan. Lokasi ini didesain lebih terbuka agar memudahkan akses bagi pembeli yang ingin membawa pulang oleh-oleh dengan cepat dan praktis."
                }
            ],
            "relatedKnowledgeIds": [],
            "contentGrounding": "identity-only",
            "aiHints": {
                "currentLocation": "Anda sekarang berada di Pusat Oleh-Oleh – Lorong Produk – Sisi Pendingin.",
                "describeHere": "Lorong1 adalah area di dalam kawasan Pusatoleholeh, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu.",
                "interestingHere": "Gunakan highlights terverifikasi.",
                "navigation": "Gunakan graph/hotspot hanya untuk navigasi."
            }
        },
        "node82": {
            "rawName": "Etalase",
            "displayName": "Etalase Oleh-oleh",
            "placeId": "pusat-oleh-oleh-etalase-oleh-oleh",
            "placeName": "Pusat Oleh-Oleh – Etalase Oleh-oleh",
            "variant": null,
            "type": "area",
            "confidence": "NEEDS_REVIEW",
            "evidence": "Bulk import via CSV/XLSX",
            "shortDescription": "Produk pajang dan lorong menjadi unsur yang terlihat pada Etalase Oleh-oleh di Pusat Oleh-Oleh.",
            "description": "Produk pajang dan lorong menjadi unsur yang terlihat pada Etalase Oleh-oleh di Pusat Oleh-Oleh. Area belanja menampilkan barang melalui rak, etalase, atau konter. Susunan pajangan membantu pengunjung membandingkan pilihan sebelum bertransaksi.",
            "highlights": [
                "Produk pajang",
                "Lorong"
            ],
            "talkingPoints": [
                "Etalase",
                "jalur eksplorasi",
                "Pusatoleholeh"
            ],
            "suggestedQuestions": [
                {
                    "q": "Mengapa Etalase Oleh-oleh menjadi bagian penting dari Pusat Oleh-Oleh – Etalase Oleh-oleh?",
                    "mode": "fix",
                    "answer": "Produk pajang dan lorong menjadi unsur yang terlihat pada Etalase Oleh-oleh di Pusat Oleh-Oleh. Produk pajang dan lorong menjadi unsur yang terlihat pada Etalase Oleh-oleh di Pusat Oleh-Oleh. Area belanja menampilkan barang melalui rak, etalase, atau konter. Susunan pajangan membantu pengunjung membandingkan pilihan sebelum bertransaksi. Area ini merupakan ruang utama yang menghubungkan beberapa etalase produk, akses lantai 2, dan tulisan autentik serta area tunggu bagi pengunjung yang datang."
                },
                {
                    "q": "Apa hubungan Produk pajang dengan aktivitas di Etalase Oleh-oleh?",
                    "mode": "fix",
                    "answer": "Produk pajang menjadi unsur yang berkaitan langsung dengan aktivitas pada area ini. Produk pajang dan lorong menjadi unsur yang terlihat pada Etalase Oleh-oleh di Pusat Oleh-Oleh."
                },
                {
                    "q": "Apa yang membedakan Etalase Oleh-oleh dari bagian lain lokasi?",
                    "mode": "fix",
                    "answer": "Sudut ini secara khusus menampilkan Produk pajang dan Lorong. Produk pajang dan lorong menjadi unsur yang terlihat pada Etalase Oleh-oleh di Pusat Oleh-Oleh."
                }
            ],
            "relatedKnowledgeIds": [],
            "contentGrounding": "identity-only",
            "aiHints": {
                "currentLocation": "Anda sekarang berada di Pusat Oleh-Oleh – Etalase Oleh-oleh.",
                "describeHere": "Etalase adalah area di dalam kawasan Pusatoleholeh, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu.",
                "interestingHere": "Gunakan highlights terverifikasi.",
                "navigation": "Gunakan graph/hotspot hanya untuk navigasi."
            }
        },
        "node83": {
            "rawName": "Lorong2",
            "displayName": "Lorong Produk – Kemasan Ringan",
            "placeId": "pusat-oleh-oleh-lorong-produk-kemasan-ringan",
            "placeName": "Pusat Oleh-Oleh – Lorong Produk – Kemasan Ringan",
            "variant": null,
            "type": "area",
            "confidence": "NEEDS_REVIEW",
            "evidence": "Bulk import via CSV/XLSX",
            "shortDescription": "Pandangan Lorong Produk – Kemasan Ringan menampilkan rak camilan dan kemasan di Pusat Oleh-Oleh.",
            "description": "Pandangan Lorong Produk – Kemasan Ringan menampilkan rak camilan dan kemasan di Pusat Oleh-Oleh. Lapangan basket dikenali melalui ring dan garis pembagian ruang permainan. Bidang lapang di sekitarnya memberi ruang bagi pergerakan pemain. Rak memanfaatkan ruang vertikal dan membagi benda dalam kelompok yang lebih mudah dilihat. Susunannya membantu pencarian serta penataan kembali setelah digunakan.",
            "highlights": [
                "Rak camilan",
                "Kemasan"
            ],
            "talkingPoints": [
                "Lorong2",
                "jalur eksplorasi",
                "Pusatoleholeh"
            ],
            "suggestedQuestions": [
                {
                    "q": "Apa latar tempat yang membantu memahami Lorong Produk – Kemasan Ringan?",
                    "mode": "fix",
                    "answer": "Bagian depan berfungsi sebagai zona penyambutan sekaligus area pajangan di mana pengunjung bisa melihat berbagai varian loenpia segar yang siap dipesan. Lokasi ini didesain lebih terbuka agar memudahkan akses bagi pembeli yang ingin membawa pulang oleh-oleh dengan cepat dan praktis."
                },
                {
                    "q": "Bagaimana susunan ruang di Lorong Produk – Kemasan Ringan mendukung kegiatannya?",
                    "mode": "fix",
                    "answer": "Susunan Rak camilan bersama Kemasan menunjukkan cara area ini digunakan. Pandangan Lorong Produk – Kemasan Ringan menampilkan rak camilan dan kemasan di Pusat Oleh-Oleh."
                },
                {
                    "q": "Detail apa yang sebaiknya tidak terlewat di Lorong Produk – Kemasan Ringan?",
                    "mode": "fix",
                    "answer": "Rak camilan menjadi detail utama, sedangkan Kemasan melengkapi konteksnya. Pandangan Lorong Produk – Kemasan Ringan menampilkan rak camilan dan kemasan di Pusat Oleh-Oleh."
                }
            ],
            "relatedKnowledgeIds": [],
            "contentGrounding": "identity-only",
            "aiHints": {
                "currentLocation": "Anda sekarang berada di Pusat Oleh-Oleh – Lorong Produk – Kemasan Ringan.",
                "describeHere": "Lorong2 adalah area di dalam kawasan Pusatoleholeh, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu.",
                "interestingHere": "Gunakan highlights terverifikasi.",
                "navigation": "Gunakan graph/hotspot hanya untuk navigasi."
            }
        },
        "node84": {
            "rawName": "Areadepan",
            "displayName": "Ambang Keluar Toko",
            "placeId": "pusat-oleh-oleh-ambang-keluar-toko",
            "placeName": "Pusat Oleh-Oleh – Ambang Keluar Toko",
            "variant": null,
            "type": "area",
            "confidence": "NEEDS_REVIEW",
            "evidence": "Bulk import via CSV/XLSX",
            "shortDescription": "Pada Ambang Keluar Toko, pintu dan papan terima kasih membentuk tampilan bagian Pusat Oleh-Oleh ini.",
            "description": "Pada Ambang Keluar Toko, pintu dan papan terima kasih membentuk tampilan bagian Pusat Oleh-Oleh ini. Pintu dan gerbang menandai perpindahan antara bagian luar dengan area yang dituju. Bentuk bukaan, papan nama, dan pelataran membantu mengenali batas tersebut. Papan dapat menunjukkan nama, tujuan, atau ketentuan penggunaan area. Letaknya menjadi acuan sebelum pengunjung memilih jalur berikutnya.",
            "highlights": [
                "Pintu",
                "Papan terima kasih"
            ],
            "talkingPoints": [
                "Areadepan",
                "jalur eksplorasi",
                "Pusatoleholeh"
            ],
            "suggestedQuestions": [
                {
                    "q": "Apa peran Ambang Keluar Toko dalam pengalaman pengunjung di Pusat Oleh-Oleh – Ambang Keluar Toko?",
                    "mode": "fix",
                    "answer": "Pada Ambang Keluar Toko, pintu dan papan terima kasih membentuk tampilan bagian Pusat Oleh-Oleh ini. Pada Ambang Keluar Toko, pintu dan papan terima kasih membentuk tampilan bagian Pusat Oleh-Oleh ini. Pintu dan gerbang menandai perpindahan antara bagian luar dengan area yang dituju. Bentuk bukaan, papan nama, dan pelataran membantu mengenali batas tersebut. Papan dapat menunjukkan nama, tujuan, atau ketentuan penggunaan area. Letaknya menjadi acuan sebelum pengunjung memilih jalur berikutnya. Area ini merupakan ruang utama yang menghubungkan beberapa etalase produk, akses lantai 2, dan tulisan autentik serta area tunggu bagi pengunjung yang datang."
                },
                {
                    "q": "Mengapa Pintu penting pada Ambang Keluar Toko?",
                    "mode": "fix",
                    "answer": "Pintu membantu menjelaskan identitas dan fungsi Ambang Keluar Toko. Pada Ambang Keluar Toko, pintu dan papan terima kasih membentuk tampilan bagian Pusat Oleh-Oleh ini."
                },
                {
                    "q": "Fakta lokal apa yang berkaitan langsung dengan Ambang Keluar Toko?",
                    "mode": "fix",
                    "answer": "Area ini merupakan ruang utama yang menghubungkan beberapa etalase produk, akses lantai 2, dan tulisan autentik serta area tunggu bagi pengunjung yang datang."
                }
            ],
            "relatedKnowledgeIds": [
                "webview:objinfo2:1168"
            ],
            "contentGrounding": "direct",
            "aiHints": {
                "currentLocation": "Anda sekarang berada di Pusat Oleh-Oleh – Ambang Keluar Toko.",
                "describeHere": "Areadepan adalah area di dalam kawasan Pusatoleholeh, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu.",
                "interestingHere": "Gunakan highlights terverifikasi.",
                "navigation": "Gunakan graph/hotspot hanya untuk navigasi."
            }
        },
        "node85": {
            "rawName": "Areatengah",
            "displayName": "Sudut Tunggu Toko",
            "placeId": "pusat-oleh-oleh-sudut-tunggu-toko",
            "placeName": "Pusat Oleh-Oleh – Sudut Tunggu Toko",
            "variant": null,
            "type": "area",
            "confidence": "NEEDS_REVIEW",
            "evidence": "Bulk import via CSV/XLSX",
            "shortDescription": "Sudut Tunggu Toko menyorot kursi dan rak, dengan konteks kunjungan di Pusat Oleh-Oleh.",
            "description": "Sudut Tunggu Toko menyorot kursi dan rak, dengan konteks kunjungan di Pusat Oleh-Oleh. Susunan objek memperlihatkan bagian fasilitas yang didokumentasikan. Hubungan antara bidang terbuka, perlengkapan, dan akses memberi konteks untuk mengenali ruang. Arah tempat duduk memberi petunjuk apakah orang diharapkan memperhatikan satu titik atau berinteraksi dalam kelompok. Jarak di antaranya juga menentukan ruang gerak.",
            "highlights": [
                "Kursi",
                "Rak"
            ],
            "talkingPoints": [
                "Areatengah",
                "jalur eksplorasi",
                "Pusatoleholeh"
            ],
            "suggestedQuestions": [
                {
                    "q": "Bagaimana Sudut Tunggu Toko menunjukkan karakter Pusat Oleh-Oleh – Sudut Tunggu Toko?",
                    "mode": "fix",
                    "answer": "Sudut Tunggu Toko menyorot kursi dan rak, dengan konteks kunjungan di Pusat Oleh-Oleh. Sudut Tunggu Toko menyorot kursi dan rak, dengan konteks kunjungan di Pusat Oleh-Oleh. Susunan objek memperlihatkan bagian fasilitas yang didokumentasikan. Hubungan antara bidang terbuka, perlengkapan, dan akses memberi konteks untuk mengenali ruang. Arah tempat duduk memberi petunjuk apakah orang diharapkan memperhatikan satu titik atau berinteraksi dalam kelompok. Jarak di antaranya juga menentukan ruang gerak. Bagian depan berfungsi sebagai zona penyambutan sekaligus area pajangan di mana pengunjung bisa melihat berbagai varian loenpia segar yang siap dipesan. Lokasi ini didesain lebih terbuka agar memudahkan akses bagi pembeli yang ingin membawa pulang oleh-oleh dengan cepat dan praktis."
                },
                {
                    "q": "Apa yang dapat dipelajari dari hubungan Kursi dan Rak di Sudut Tunggu Toko?",
                    "mode": "fix",
                    "answer": "Keduanya memperlihatkan hubungan antara bentuk ruang, fungsi, dan aktivitas pengunjung. Sudut Tunggu Toko menyorot kursi dan rak, dengan konteks kunjungan di Pusat Oleh-Oleh."
                },
                {
                    "q": "Detail mana yang paling informatif untuk mengenali Sudut Tunggu Toko?",
                    "mode": "fix",
                    "answer": "Kursi paling langsung membantu mengenali area ini. Sudut Tunggu Toko menyorot kursi dan rak, dengan konteks kunjungan di Pusat Oleh-Oleh."
                }
            ],
            "relatedKnowledgeIds": [
                "webview:objinfo2:1169"
            ],
            "contentGrounding": "direct",
            "aiHints": {
                "currentLocation": "Anda sekarang berada di Pusat Oleh-Oleh – Sudut Tunggu Toko.",
                "describeHere": "Areatengah adalah area di dalam kawasan Pusatoleholeh, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu.",
                "interestingHere": "Gunakan highlights terverifikasi.",
                "navigation": "Gunakan graph/hotspot hanya untuk navigasi."
            }
        },
        "node86": {
            "rawName": "Halte",
            "displayName": "Pintu Pusat Oleh-oleh Djoe",
            "placeId": "pusat-oleh-oleh-pintu-pusat-oleh-oleh-djoe",
            "placeName": "Pusat Oleh-Oleh – Pintu Pusat Oleh-oleh Djoe",
            "variant": null,
            "type": "area",
            "confidence": "NEEDS_REVIEW",
            "evidence": "Bulk import via CSV/XLSX",
            "shortDescription": "Pintu Pusat Oleh-oleh Djoe di Pusat Oleh-Oleh memperlihatkan papan nama dan kasir.",
            "description": "Pintu Pusat Oleh-oleh Djoe di Pusat Oleh-Oleh memperlihatkan papan nama dan kasir. Area belanja menampilkan barang melalui rak, etalase, atau konter. Susunan pajangan membantu pengunjung membandingkan pilihan sebelum bertransaksi. Papan dapat menunjukkan nama, tujuan, atau ketentuan penggunaan area. Letaknya menjadi acuan sebelum pengunjung memilih jalur berikutnya.",
            "highlights": [
                "Papan nama",
                "Kasir"
            ],
            "talkingPoints": [
                "Halte",
                "jalur eksplorasi",
                "Pusatoleholeh"
            ],
            "suggestedQuestions": [
                {
                    "q": "Mengapa pengunjung perlu mengenal Pintu Pusat Oleh-oleh Djoe?",
                    "mode": "fix",
                    "answer": "Pintu Pusat Oleh-oleh Djoe di Pusat Oleh-Oleh memperlihatkan papan nama dan kasir. Pintu Pusat Oleh-oleh Djoe di Pusat Oleh-Oleh memperlihatkan papan nama dan kasir. Area belanja menampilkan barang melalui rak, etalase, atau konter. Susunan pajangan membantu pengunjung membandingkan pilihan sebelum bertransaksi. Papan dapat menunjukkan nama, tujuan, atau ketentuan penggunaan area. Letaknya menjadi acuan sebelum pengunjung memilih jalur berikutnya. Area ini merupakan ruang utama yang menghubungkan beberapa etalase produk, akses lantai 2, dan tulisan autentik serta area tunggu bagi pengunjung yang datang."
                },
                {
                    "q": "Aktivitas apa yang didukung oleh susunan Pintu Pusat Oleh-oleh Djoe?",
                    "mode": "fix",
                    "answer": "Keberadaan Papan nama dan Kasir memberi petunjuk tentang kegiatan yang berlangsung di sini. Pintu Pusat Oleh-oleh Djoe di Pusat Oleh-Oleh memperlihatkan papan nama dan kasir."
                },
                {
                    "q": "Apa konteks Pusat Oleh-Oleh – Pintu Pusat Oleh-oleh Djoe yang membuat Pintu Pusat Oleh-oleh Djoe lebih bermakna?",
                    "mode": "fix",
                    "answer": "Area ini merupakan ruang utama yang menghubungkan beberapa etalase produk, akses lantai 2, dan tulisan autentik serta area tunggu bagi pengunjung yang datang."
                }
            ],
            "relatedKnowledgeIds": [],
            "contentGrounding": "identity-only",
            "aiHints": {
                "currentLocation": "Anda sekarang berada di Pusat Oleh-Oleh – Pintu Pusat Oleh-oleh Djoe.",
                "describeHere": "Halte adalah area di dalam kawasan Pusatoleholeh, Semarang. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu.",
                "interestingHere": "Gunakan highlights terverifikasi.",
                "navigation": "Gunakan graph/hotspot hanya untuk navigasi."
            }
        }
    },
    "modernObjectInfo": {
        "1702": {
            "name": {
                "id": "Halte Trans Semarang"
            },
            "image": "halte-trans-semarang-haltetranssemarang.webp"
        },
        "1703": {
            "name": {
                "id": "Rengginang"
            },
            "image": "rengginang-rengginang.webp"
        },
        "1704": {
            "name": {
                "id": "Wajit Cililin"
            },
            "image": "wajit-cililin-wajitcililin.webp"
        },
        "1705": {
            "name": {
                "id": "Kue Bagelen"
            },
            "image": "kue-bagelen-kuebagelan.webp"
        },
        "1706": {
            "name": {
                "id": "Bakpia Pathok"
            },
            "image": "bakpia-pathok-bakpiapathok.webp"
        },
        "1707": {
            "name": {
                "id": "Lumpia Semarang"
            },
            "image": "lumpia-semarang-lumpiasemarang.webp"
        },
        "1708": {
            "name": {
                "id": "Jenang Kudus"
            },
            "image": "jenang-kudus-jenangkudus.webp"
        }
    }
};
