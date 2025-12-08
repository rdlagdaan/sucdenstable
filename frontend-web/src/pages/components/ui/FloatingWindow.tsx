import React, { useEffect, useRef, useState } from 'react';

interface Props {
  title: string;
  defaultWidth?: number;
  defaultHeight?: number;
  minimized?: boolean;
  onMinimize?: (minimized: boolean) => void;
  onClose?: () => void;
  children: React.ReactNode;
}

type StartRef = {
  x: number; y: number; px: number; py: number; w: number; h: number; edge?: ResizeEdge | null;
};
type ResizeEdge = 'right' | 'bottom' | 'left' | 'top' | 'corner';

const MIN_W = 480;
const MIN_H = 280;

const clamp = (val: number, min: number, max: number) => Math.max(min, Math.min(max, val));

const FloatingWindow: React.FC<Props> = ({
  title,
  defaultWidth = 700,
  defaultHeight = 500,
  minimized = false,
  onMinimize,
  onClose,
  children,
}) => {
  const [pos, setPos] = useState({ x: 80, y: 80 });
  const [size, setSize] = useState({ w: defaultWidth, h: defaultHeight });
  const [isDragging, setDragging] = useState(false);
  const [isResizing, setResizing] = useState<ResizeEdge | null>(null);
  const [isMinimized, setIsMinimized] = useState(minimized);
  const [isMaximized, setIsMaximized] = useState(false);
  const prevBeforeMax = useRef<{ pos: { x: number; y: number }; size: { w: number; h: number } } | null>(null);
  const startRef = useRef<StartRef | null>(null);

  useEffect(() => setIsMinimized(minimized), [minimized]);

  // --- Drag ---
  const onMouseDownDrag = (e: React.MouseEvent) => {
    if (isMaximized) return; // no drag while maximized
    setDragging(true);
    startRef.current = { x: e.clientX, y: e.clientY, px: pos.x, py: pos.y, w: size.w, h: size.h, edge: null };
  };

  // --- Resize helpers ---
  const beginResize = (edge: ResizeEdge) => (e: React.MouseEvent) => {
    e.stopPropagation();
    if (isMaximized) return; // cannot resize while maximized
    setResizing(edge);
    startRef.current = { x: e.clientX, y: e.clientY, px: pos.x, py: pos.y, w: size.w, h: size.h, edge };
  };

  const onMove = (e: MouseEvent) => {
    const vw = window.innerWidth;
    const vh = window.innerHeight;

    if (isDragging && startRef.current) {
      const dx = e.clientX - startRef.current.x;
      const dy = e.clientY - startRef.current.y;
      const nx = clamp(startRef.current.px + dx, 0, vw - size.w);
      const ny = clamp(startRef.current.py + dy, 0, vh - size.h);
      setPos({ x: nx, y: ny });
    } else if (isResizing && startRef.current) {
      const { edge } = startRef.current;
      let { px, py, w, h } = startRef.current;
      const dx = e.clientX - startRef.current.x;
      const dy = e.clientY - startRef.current.y;

      let nx = px, ny = py, nw = w, nh = h;

      if (edge === 'right' || edge === 'corner') nw = clamp(w + dx, MIN_W, vw - px);
      if (edge === 'bottom' || edge === 'corner') nh = clamp(h + dy, MIN_H, vh - py);
      if (edge === 'left') {
        nx = clamp(px + dx, 0, px + w - MIN_W);
        nw = clamp(w - dx, MIN_W, vw - nx);
      }
      if (edge === 'top') {
        ny = clamp(py + dy, 0, py + h - MIN_H);
        nh = clamp(h - dy, MIN_H, vh - ny);
      }

      // Keep in viewport
      nx = clamp(nx, 0, Math.max(0, vw - nw));
      ny = clamp(ny, 0, Math.max(0, vh - nh));

      setPos({ x: nx, y: ny });
      setSize({ w: nw, h: nh });
    }
  };

  const onUp = () => { setDragging(false); setResizing(null); };

  useEffect(() => {
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
    return () => {
      document.removeEventListener('mousemove', onMove);
      document.removeEventListener('mouseup', onUp);
    };
  });

  // --- Minimize ---
  const toggleMin = () => {
    const val = !isMinimized;
    setIsMinimized(val);
    onMinimize?.(val);
  };

  // --- Maximize / Restore ---
  const maximize = () => {
    if (isMaximized) return;
    prevBeforeMax.current = { pos: { ...pos }, size: { ...size } };
    setPos({ x: 0, y: 0 });
    setSize({ w: window.innerWidth, h: window.innerHeight - 16 }); // small breathing room
    setIsMaximized(true);
    setIsMinimized(false);
  };
  const restore = () => {
    const prev = prevBeforeMax.current;
    if (!prev) return;
    setPos(prev.pos);
    setSize(prev.size);
    setIsMaximized(false);
  };
  const toggleMax = () => (isMaximized ? restore() : maximize());

  // Re-clamp on window resize while maximized
  useEffect(() => {
    const onResize = () => {
      if (isMaximized) {
        setPos({ x: 0, y: 0 });
        setSize({ w: window.innerWidth, h: window.innerHeight - 16 });
      }
    };
    window.addEventListener('resize', onResize);
    return () => window.removeEventListener('resize', onResize);
  }, [isMaximized]);

  return (
    <div
      className="fixed bg-white shadow-2xl rounded-xl border border-gray-200 overflow-hidden z-[999]"
      style={{
        left: pos.x,
        top: pos.y,
        width: size.w,
        height: isMinimized ? 'auto' : size.h
      }}
    >
      <div
        className="cursor-move select-none px-4 py-2 bg-white/90 backdrop-blur border-b flex items-center justify-between"
        onMouseDown={onMouseDownDrag}
        onDoubleClick={toggleMax}
      >
        <span className="font-semibold text-gray-800">{title}</span>
        <div className="flex items-center gap-2">
          <button onClick={toggleMax} className="px-2 py-1 rounded hover:bg-gray-100 text-xs">
            {isMaximized ? 'Restore' : 'Maximize'}
          </button>
          <button onClick={toggleMin} className="px-2 py-1 rounded hover:bg-gray-100 text-xs">
            {isMinimized ? 'Restore' : 'Minimize'}
          </button>
          <button onClick={onClose} className="px-2 py-1 rounded hover:bg-red-50 text-red-600 text-xs">
            Close
          </button>
        </div>
      </div>

      {!isMinimized && (
        <div className="w-full h-[calc(100%-42px)] overflow-auto bg-white">
          {children}
        </div>
      )}

      {/* Resize handles - invisible but hit-testable */}
      {!isMinimized && !isMaximized && (
        <>
          <div onMouseDown={beginResize('right')}  className="absolute right-0 top-0 w-2 h-full cursor-ew-resize" />
          <div onMouseDown={beginResize('left')}   className="absolute left-0 top-0 w-2 h-full cursor-ew-resize" />
          <div onMouseDown={beginResize('bottom')} className="absolute left-0 bottom-0 w-full h-2 cursor-ns-resize" />
          <div onMouseDown={beginResize('top')}    className="absolute left-0 top-0 w-full h-2 cursor-ns-resize" />
          <div onMouseDown={beginResize('corner')} className="absolute right-0 bottom-0 w-5 h-5 cursor-nwse-resize" title="Resize" />
        </>
      )}
    </div>
  );
};

export default FloatingWindow;
