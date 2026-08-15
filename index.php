<?php
session_start();

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header("Location: login.php");
    exit;
}

if (empty($_SESSION['can_edit'])) {
    header("Location: index2.php");
    exit;
}

require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Our Story</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22 fill=%22none%22 stroke=%22%23ec4899%22 stroke-width=%222%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22><path d=%22M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5%22/></svg>">
    <!-- Versioned local assets avoid runtime CDN dependencies. -->
    <link rel="stylesheet" href="assets/tailwind.css">
    <script src="assets/react.production.min.js"></script>
    <script src="assets/react-dom.production.min.js"></script>
    <!-- Babel 7 supports this browser-side JSX setup. -->
    <script src="assets/babel.min.js"></script>
    <script src="assets/lucide.min.js"></script>

    <style>
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #fee2e2; border-radius: 10px; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .animate-fadeIn { animation: fadeIn 0.3s ease-in-out; }

        .tooltip { position: relative; display: inline-block; }
        .tooltip .tooltiptext {
            visibility: hidden; width: 120px; background-color: #555; color: #fff; text-align: center;
            border-radius: 6px; padding: 5px 0; position: absolute; z-index: 1; bottom: 125%; left: 50%;
            margin-left: -60px; opacity: 0; transition: opacity 0.3s; font-size: 10px;
        }
        .tooltip:hover .tooltiptext { visibility: visible; opacity: 1; }

        body { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
    </style>
</head>
<body class="bg-rose-50/30 text-gray-800 font-sans">
    <div id="root"></div>

    <script type="text/babel">
        const { useState, useEffect, useRef } = React;

        const Icon = ({ name, size = 20, className = "" }) => {
            const containerRef = useRef(null);
            useEffect(() => {
                if (window.lucide && containerRef.current) {
                    containerRef.current.innerHTML = `<i data-lucide="${name}" class="${className}" style="width: ${size}px; height: ${size}px;"></i>`;
                    window.lucide.createIcons({ root: containerRef.current });
                }
            }, [name, size, className]);
            return <span ref={containerRef} className="inline-flex items-center justify-center pointer-events-none"></span>;
        };

        const App = () => {
            const [memories, setMemories] = useState([]);
            const [loading, setLoading] = useState(true);
            const [isModalOpen, setIsModalOpen] = useState(false);
            const [editingId, setEditingId] = useState(null);
            const [activeMapIndices, setActiveMapIndices] = useState({});
            const [sortBy, setSortBy] = useState('date-desc');

            const initialFormState = {
                date: new Date().toISOString().split('T')[0],
                locations: [{ id: Date.now(), name: '' }],
                photoUrl: '',
                albumUrl: '',
                description: ''
            };
            const [formData, setFormData] = useState(initialFormState);

            // Normalize place names and Google Maps URLs for the embed.
            const getMapUrl = (input) => {
                if (!input) return "";
                let query = input;

                if (input.includes('google.com/maps')) {
                    const placeMatch = input.match(/place\/([^\/]+)/);
                    if (placeMatch && placeMatch[1]) {
                        query = decodeURIComponent(placeMatch[1].replace(/\+/g, ' '));
                    } else {
                        const qMatch = input.match(/[?&]q=([^&]+)/);
                        if (qMatch && qMatch[1]) {
                            query = decodeURIComponent(qMatch[1].replace(/\+/g, ' '));
                        }
                    }
                }

                return `https://maps.google.com/maps?q=${encodeURIComponent(query)}&t=&z=15&ie=UTF8&iwloc=&output=embed`;
            };

            const fetchMemories = async () => {
                try {
                    const res = await fetch('api.php');
                    if (res.status === 401) {
                        window.location.href = "login.php";
                        return;
                    }
                    const data = await res.json();
                    const formatted = data.map(m => ({
                        ...m,
                        photoUrl: m.photo_url || m.photoUrl,
                        albumUrl: m.album_url || m.albumUrl,
                        locations: typeof m.locations === 'string' ? JSON.parse(m.locations) : m.locations
                    }));
                    setMemories(formatted);
                } catch (e) {
                    console.error("Fetch error:", e);
                } finally {
                    setLoading(false);
                }
            };

            useEffect(() => { fetchMemories(); }, []);

            const sortedMemories = [...memories].sort((a, b) => {
                if (sortBy === 'date-desc') return new Date(b.date) - new Date(a.date);
                if (sortBy === 'date-asc') return new Date(a.date) - new Date(b.date);
                if (sortBy === 'update-desc') return new Date(b.updated_at) - new Date(a.updated_at);
                return 0;
            });

            const handleSave = async (e) => {
                e.preventDefault();
                const validLocs = formData.locations.filter(l => l.name.trim());
                if (!validLocs.length) return;

                const payload = {
                    id: editingId,
                    date: formData.date,
                    locations: validLocs,
                    photoUrl: formData.photoUrl.trim() || 'assets/images/fallback.jpg',
                    albumUrl: formData.albumUrl.trim(),
                    description: formData.description.trim()
                };

                try {
                    const res = await fetch('api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    if (res.status === 401) {
                        window.location.href = "login.php";
                        return;
                    }

                    if (res.ok) {
                        setIsModalOpen(false);
                        fetchMemories();
                    }
                } catch (e) {
                    console.error("Save error:", e);
                }
            };

            const handleDelete = async (id) => {
                if (!confirm("この思い出を削除しますか？")) return;
                try {
                    const res = await fetch(`api.php?id=${id}`, { method: 'DELETE' });
                    if (res.status === 401) {
                        window.location.href = "login.php";
                        return;
                    }
                    fetchMemories();
                } catch (e) {
                    console.error("Delete error:", e);
                }
            };

            if (loading) return (
                <div className="min-h-screen flex items-center justify-center bg-rose-50/30">
                    <div className="flex flex-col items-center gap-4">
                        <Icon name="heart" size={48} className="text-pink-300 animate-pulse" />
                        <p className="text-pink-400 font-bold tracking-widest text-xs uppercase">Memory Loading...</p>
                    </div>
                </div>
            );

            return (
                <div className="min-h-screen pb-24 animate-fadeIn">
                    <header className="bg-white/80 backdrop-blur-md sticky top-0 z-40 border-b border-rose-100 px-6 py-4 flex justify-between items-center">
                        <div className="flex items-center gap-2">
                            <Icon name="heart" className="text-pink-500" size={24} />
                            <h1 className="text-xl font-black tracking-tighter uppercase">Our <span className="text-pink-500">Story</span></h1>
                            <div className="tooltip">
                                <span className="ml-2 bg-blue-100 text-blue-600 text-[10px] px-2 py-0.5 rounded-full font-bold"><?= htmlspecialchars(db_engine_label(), ENT_QUOTES) ?></span>
                                <span className="tooltiptext"><?= htmlspecialchars(app_platform_label(), ENT_QUOTES) ?></span>
                            </div>
                        </div>

                        <div className="flex items-center gap-3">
                            <div className="relative hidden sm:flex items-center">
                                <Icon name="filter" size={16} className="absolute left-3 text-gray-400" />
                                <select
                                    value={sortBy}
                                    onChange={(e) => setSortBy(e.target.value)}
                                    className="pl-9 pr-4 py-2.5 bg-gray-50 border-none rounded-2xl text-xs font-bold text-gray-500 focus:ring-2 focus:ring-pink-200 outline-none appearance-none transition-all cursor-pointer"
                                >
                                    <option value="date-desc">新しい順</option>
                                    <option value="date-asc">古い順</option>
                                    <option value="update-desc">更新順</option>
                                </select>
                            </div>

                            <button onClick={() => { setEditingId(null); setFormData(initialFormState); setIsModalOpen(true); }} className="bg-pink-500 hover:bg-pink-600 text-white px-5 py-2.5 rounded-2xl shadow-lg shadow-pink-200 flex items-center gap-2 font-bold text-sm transition-all active:scale-95">
                                <Icon name="plus-circle" size={20} /> 思い出を追加
                            </button>
                        </div>
                    </header>

                    <main className="max-w-5xl mx-auto px-6 py-10 space-y-12">
                        {sortedMemories.length ? sortedMemories.map((m) => {
                            const activeIdx = activeMapIndices[m.id] || 0;
                            const currentLocInput = m.locations[activeIdx]?.name;

                            return (
                                <div key={m.id} className="bg-white rounded-[2.5rem] shadow-xl overflow-hidden border border-white flex flex-col lg:flex-row transition-all hover:shadow-2xl">
                                    <div className="lg:w-1/2 h-80 lg:h-[480px] relative overflow-hidden bg-gray-200 group">
                                        <img src={m.photoUrl} alt="Cover" className="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" onError={(e) => { e.target.src = 'assets/images/fallback.jpg'; }} />
                                        <div className="absolute inset-0 bg-gradient-to-t from-black/80 via-transparent to-transparent" />
                                        <div className="absolute bottom-8 left-8 right-8 text-white space-y-3">
                                            <div className="flex items-center gap-2 text-xs font-bold opacity-90"><Icon name="calendar" size={14} />{m.date}</div>
                                            <h2 className="text-3xl font-black truncate">{m.locations[0]?.name.split('/').pop()} {m.locations.length > 1 && `+${m.locations.length - 1}`}</h2>
                                            {m.albumUrl && <button onClick={() => window.open(m.albumUrl, '_blank')} className="bg-white/20 hover:bg-white/40 backdrop-blur-md px-5 py-2 rounded-2xl flex items-center gap-2 font-bold text-sm border border-white/30 transition-all"><Icon name="camera" size={18} /> Album <Icon name="chevron-right" size={16} /></button>}
                                        </div>
                                    </div>

                                    <div className="lg:w-1/2 flex flex-col bg-gray-50/50">
                                        {/* Keep the map usable on narrow screens. */}
                                        <div className="relative h-[300px] lg:flex-1 lg:min-h-[300px]">
                                            <iframe
                                                src={getMapUrl(currentLocInput)}
                                                className="absolute inset-0 w-full h-full border-0 grayscale-[0.1]"
                                                title="map"
                                                allowFullScreen
                                            />
                                            <div className="absolute top-4 right-4 bg-white/90 px-3 py-1.5 rounded-xl text-[10px] font-black text-blue-600 shadow-sm border border-blue-50 flex items-center gap-1.5 max-w-[80%]">
                                                <Icon name="map-pin" size={12} className="flex-shrink-0" />
                                                <span className="truncate">{currentLocInput?.includes('http') ? '指定された場所' : currentLocInput}</span>
                                            </div>
                                        </div>
                                        <div className="p-8 bg-white border-t border-gray-100 space-y-4">
                                            {m.locations.length > 0 && (
                                                <div className="flex gap-2 overflow-x-auto pb-2 no-scrollbar">
                                                    {m.locations.map((loc, i) => (
                                                        <button key={loc.id || i} onClick={() => setActiveMapIndices({...activeMapIndices, [m.id]: i})} className={`px-4 py-2 rounded-xl text-xs font-bold border whitespace-nowrap transition-all ${activeIdx === i ? 'bg-blue-500 text-white border-blue-500 shadow-md shadow-blue-100' : 'bg-white text-gray-400 border-gray-100 hover:border-blue-200'}`}>
                                                            {loc.name.includes('http') ? `スポット ${i+1}` : loc.name}
                                                        </button>
                                                    ))}
                                                </div>
                                            )}
                                            {/* Preserve line breaks in descriptions. */}
                                            <p className="text-gray-600 text-base italic font-medium leading-relaxed whitespace-pre-wrap">"{m.description?.trim() || "二人の大切な一日。"}"</p>
                                            <div className="flex justify-between items-center pt-4 border-t border-gray-50">
                                                <div className="flex gap-3">
                                                    <button onClick={() => { setEditingId(m.id); setFormData({...m}); setIsModalOpen(true); }} className="p-3 bg-gray-50 text-gray-400 hover:text-blue-500 hover:bg-blue-50 rounded-2xl transition-all"><Icon name="pencil" size={20} /></button>
                                                    <button onClick={() => handleDelete(m.id)} className="p-3 bg-gray-50 text-gray-400 hover:text-red-500 hover:bg-red-50 rounded-2xl transition-all"><Icon name="trash-2" size={20} /></button>
                                                </div>
                                                <button onClick={() => {
                                                    const target = currentLocInput.includes('http') ? currentLocInput : `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(currentLocInput)}`;
                                                    window.open(target, '_blank');
                                                }} className="text-blue-500 font-bold text-sm flex items-center gap-1 hover:underline tracking-tighter">
                                                    View on Google Maps <Icon name="external-link" size={14} />
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            );
                        }) : (
                            <div className="text-center py-32 opacity-20">
                                <Icon name="camera" size={64} className="mx-auto mb-4" />
                                <p className="text-xl font-bold tracking-widest uppercase">No Memories Yet</p>
                            </div>
                        )}
                    </main>

                    {isModalOpen && (
                        <div className="fixed inset-0 bg-black/60 backdrop-blur-md z-50 flex items-center justify-center p-4">
                            <form onSubmit={handleSave} className="bg-white w-full max-w-lg rounded-[2.5rem] shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-300">
                                <div className="p-6 border-b border-gray-50 flex justify-between items-center bg-rose-50/20">
                                    <h3 className="font-black text-gray-800 flex items-center gap-2 uppercase tracking-tighter text-lg"><Icon name="heart" size={18} className="text-pink-500" /> {editingId ? "Edit Memory" : "New Memory"}</h3>
                                    <button type="button" onClick={() => setIsModalOpen(false)} className="bg-white p-2 rounded-full text-gray-400 hover:text-gray-600 shadow-sm"><Icon name="x" size={20} /></button>
                                </div>
                                <div className="p-8 space-y-5 max-h-[75vh] overflow-y-auto custom-scrollbar">
                                    <div className="space-y-1 text-left">
                                        <label className="text-[10px] font-black text-gray-400 uppercase tracking-widest ml-1">日付</label>
                                        <input type="date" required value={formData.date} onChange={e => setFormData({...formData, date: e.target.value})} className="w-full px-5 py-3.5 bg-gray-50 rounded-2xl font-bold border-none focus:ring-2 focus:ring-pink-500 transition-all outline-none" />
                                    </div>
                                    <div className="space-y-2 text-left">
                                        <label className="text-[10px] font-black text-gray-400 uppercase tracking-widest ml-1">スポット</label>
                                        {formData.locations.map((loc, i) => (
                                            <div key={loc.id || i} className="flex gap-2 animate-fadeIn">
                                                <input type="text" placeholder="場所名 または 地図URL" required value={loc.name} onChange={e => { const updated = [...formData.locations]; updated[i].name = e.target.value; setFormData({...formData, locations: updated}); }} className="flex-1 px-5 py-3.5 bg-gray-50 rounded-2xl font-bold border-none focus:ring-2 focus:ring-pink-500 transition-all outline-none" />
                                                {formData.locations.length > 1 && <button type="button" onClick={() => setFormData({...formData, locations: formData.locations.filter((_, idx) => idx !== i)})} className="p-3 text-rose-300 hover:text-rose-500 transition-colors"><Icon name="trash-2" size={18} /></button>}
                                            </div>
                                        ))}
                                        <button type="button" onClick={() => setFormData({...formData, locations: [...formData.locations, { id: Date.now(), name: '' }]})} className="w-full py-2.5 border-2 border-dashed border-gray-100 rounded-2xl text-[10px] font-black text-gray-400 hover:text-pink-400 hover:border-pink-100 transition-all uppercase tracking-widest">+ スポットを追加</button>
                                    </div>

                                    <div className="space-y-1 text-left">
                                        <label className="text-[10px] font-black text-gray-400 uppercase tracking-widest ml-1">カバー写真URL</label>
                                        <input type="url" placeholder="https://..." value={formData.photoUrl} onChange={e => setFormData({...formData, photoUrl: e.target.value})} className="w-full px-5 py-3.5 bg-gray-50 rounded-2xl outline-none border-none focus:ring-2 focus:ring-pink-500 text-sm font-medium transition-all" />
                                    </div>

                                    <div className="space-y-1 text-left">
                                        <label className="text-[10px] font-black text-gray-400 uppercase tracking-widest ml-1">アルバムURL</label>
                                        <input type="url" placeholder="Googleフォト等の共有リンク" value={formData.albumUrl} onChange={e => setFormData({...formData, albumUrl: e.target.value})} className="w-full px-5 py-3.5 bg-gray-50 rounded-2xl border-none focus:ring-2 focus:ring-pink-500 transition-all outline-none font-medium" />
                                    </div>
                                    <div className="space-y-1 text-left">
                                        <label className="text-[10px] font-black text-gray-400 uppercase tracking-widest ml-1">メモ</label>
                                        <textarea placeholder="今日の思い出を自由に書こう..." rows="3" value={formData.description} onChange={e => setFormData({...formData, description: e.target.value})} className="w-full px-5 py-3.5 bg-gray-50 rounded-2xl border-none focus:ring-2 focus:ring-pink-500 outline-none resize-none font-medium transition-all" />
                                    </div>
                                    <button type="submit" className="w-full bg-pink-500 text-white font-black py-4 rounded-[1.5rem] shadow-xl shadow-pink-100 flex items-center justify-center gap-3 transition-all active:scale-[0.98] mt-4 hover:bg-pink-600">思い出を保存 <Icon name="heart" size={18} /></button>
                                </div>
                            </form>
                        </div>
                    )}
                </div>
            );
        };

        const root = ReactDOM.createRoot(document.getElementById('root'));
        root.render(<App />);
    </script>
</body>
</html>