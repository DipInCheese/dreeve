const v5Theme = () => {
    const backgroundColor = 'transparent';
    const gradientColor = ['#f6efa6', '#d88273', '#bf444c'];

    const axisCommon = function () {
        return {
            axisLine: {
                lineStyle: {
                    color: '#6E7079'
                }
            },
            axisLabel: {
                color: null
            },
            splitLine: {
                lineStyle: {
                    color: ['#E0E6F1']
                }
            },
            splitArea: {
                areaStyle: {
                    color: ['rgba(250,250,250,0.2)', 'rgba(210,219,238,0.2)']
                }
            },
            minorSplitLine: {
                color: '#F4F7FD'
            }
        };
    };

    return {
        backgroundColor: backgroundColor,
        color: [
            '#5470c6',
            '#91cc75',
            '#fac858',
            '#ee6666',
            '#73c0de',
            '#3ba272',
            '#fc8452',
            '#9a60b4',
            '#ea7ccc'
        ],
        gradientColor: gradientColor,
        loading: {
            textColor: 'red'
        },
        bar: {
            defaultBarGap: '20%',
            select: {
                itemStyle: {
                    borderColor: '#212121',
                    borderWidth: 1
                }
            }
        },
        boxplot: {
            emphasis: {
                itemStyle: {
                    shadowColor: 'rgba(0,0,0,0.2)'
                }
            }
        },
        graph: {
            lineStyle: {
                color: '#aaa'
            },
            select: {
                itemStyle: {
                    borderColor: '#212121'
                }
            }
        },
        heatmap: {
            select: {
                itemStyle: {
                    borderColor: '#212121'
                }
            }
        },
        line: {
            symbolSize: 4
        },
        pictorialBar: {
            select: {
                itemStyle: {
                    borderColor: '#212121',
                    borderWidth: 1
                }
            }
        },
        pie: {
            radius: [0, '75%'],
            labelLine: {
                length2: 15
            }
        },
        map: {
            defaultItemStyleColor: '#eee',
            label: {
                color: '#000'
            },
            itemStyle: {
                borderColor: '#444',
                areaColor: '#eee'
            },
            emphasis: {
                label: {
                    color: 'rgb(100,0,0)'
                },
                itemStyle: {
                    areaColor: 'rgba(255,215,0,0.8)'
                }
            },
            select: {
                label: {
                    color: 'rgb(100,0,0)'
                },
                itemStyle: {
                    color: 'rgba(255,215,0,0.8)'
                }
            },
        },
        timeAxis: axisCommon(),
        logAxis: axisCommon(),
        valueAxis: axisCommon(),
        categoryAxis: (() => {
            const axis = axisCommon();
            axis.axisTick = {
                show: true
            };
            return axis;
        })(),
        axisPointer: {
            lineStyle: {
                color: '#B9BEC9'
            },
            shadowStyle: {
                color: 'rgba(210,219,238,0.2)'
            },
            label: {
                backgroundColor: 'auto',
                color: '#fff'
            },
            handle: {
                color: '#333',
                shadowBlur: 3,
                shadowColor: '#aaa',
                shadowOffsetX: 0,
                shadowOffsetY: 2,
            }
        },
        brush: {
            brushStyle: {
                color: 'rgba(210,219,238,0.3)',
                borderColor: '#D2DBEE'
            },
            defaultOutOfBrushColor: '#ddd'
        },
        calendar: {
            splitLine: {
                lineStyle: {
                    color: '#000'
                }
            },
            itemStyle: {
                borderColor: '#ccc'
            },
            dayLabel: {
                margin: '50%',
                color: '#000'
            },
            monthLabel: {
                margin: 5,
                color: '#000'
            },
            yearLabel: {
                margin: 30,
                color: '#ccc'
            }
        },
        dataZoom: {
            borderColor: '#d2dbee',
            borderRadius: 3,
            backgroundColor: 'rgba(47,69,84,0)',
            dataBackground: {
                lineStyle: {
                    color: '#d2dbee',
                    width: 0.5
                },
                areaStyle: {
                    color: '#d2dbee',
                    opacity: 0.2
                }
            },
            selectedDataBackground: {
                lineStyle: {
                    color: '#8fb0f7',
                    width: 0.5
                },
                areaStyle: {
                    color: '#8fb0f7',
                    opacity: 0.2
                }
            },
            handleStyle: {
                color: '#fff',
                borderColor: '#ACB8D1'
            },
            moveHandleStyle: {
                color: '#D2DBEE',
                opacity: 0.7
            },
            textStyle: {
                color: '#6E7079'
            },
            brushStyle: {
                color: 'rgba(135,175,274,0.15)'
            },
            emphasis: {
                handleStyle: {
                    borderColor: '#8FB0F7'
                },
                moveHandleStyle: {
                    color: '#8FB0F7',
                    opacity: 0.7
                }
            },
            defaultLocationEdgeGap: 7
        },
        geo: {
            defaultItemStyleColor: '#eee',
            label: {
                color: '#000'
            },
            itemStyle: {
                borderColor: '#444'
            },
            emphasis: {
                label: {
                    color: 'rgb(100,0,0)'
                },
                itemStyle: {
                    color: 'rgba(255,215,0,0.8)'
                }
            },
            select: {
                label: {
                    color: 'rgb(100,0,0)'
                },
                itemStyle: {
                    color: 'rgba(255,215,0,0.8)'
                }
            }
        },
        grid: {
            left: '10%',
            top: 60,
            bottom: 70,
            borderColor: '#ccc'
        },
        legend: {
            top: 0,
            bottom: null,
            backgroundColor: 'rgba(0,0,0,0)',
            borderColor: '#ccc',
            itemGap: 10,
            inactiveColor: '#ccc',
            inactiveBorderColor: '#ccc',
            lineStyle: {
                inactiveColor: '#ccc',
            },
            textStyle: {
                color: '#333'
            },
            selectorLabel: {
                color: '#666',
                borderColor: '#666'
            },
            emphasis: {
                selectorLabel: {
                    color: '#eee',
                    backgroundColor: '#666'
                }
            },
            pageIconColor: '#2f4554',
            pageIconInactiveColor: '#aaa',
            pageTextStyle: {
                color: '#333'
            }
        },
        title: {
            left: 0,
            top: 0,
            backgroundColor: 'rgba(0,0,0,0)',
            borderColor: '#ccc',
            textStyle: {
                color: '#464646'
            },
            subtextStyle: {
                color: '#6E7079'
            }
        },
        toolbox: {
            borderColor: '#ccc',
            padding: 5,
            itemGap: 8,
            iconStyle: {
                borderColor: '#666',
            },
            emphasis: {
                iconStyle: {
                    borderColor: '#3E98C5'
                }
            }
        },
        tooltip: {
            axisPointer: {
                crossStyle: {
                    color: '#999'
                }
            },
            textStyle: {
                color: '#666'
            },
            backgroundColor: '#fff',
            borderWidth: 1,
            defaultBorderColor: '#fff'
        },
        visualMap: {
            color: [gradientColor[2], gradientColor[1], gradientColor[0]],
            inactive: ['rgba(0,0,0,0)'],
            indicatorStyle: {
                shadowColor: 'rgba(0,0,0,0.2)'
            },
            backgroundColor: 'rgba(0,0,0,0)',
            borderColor: '#ccc',
            contentColor: '#5793f3',
            inactiveColor: '#aaa',
            padding: 5,
            textStyle: {
                color: '#333'
            }
        }
    };
}

const v5DarkTheme = () => {
    const base = v5Theme();
    const backgroundColor = 'transparent';
    // Warm and faint, so the charts sit on the smoky glass instead of on top of it.
    const contrastColor = '#E6DCD2';

    const axisCommon = function () {
        return {
            axisLine: {
                lineStyle: {
                    color: 'rgba(255, 240, 225, 0.22)'
                }
            },
            axisLabel: {
                color: 'rgba(255, 245, 235, 0.78)'
            },
            splitLine: {
                lineStyle: {
                    color: 'rgba(255, 240, 225, 0.09)'
                }
            },
            splitArea: {
                areaStyle: {
                    color: ['rgba(255,255,255,0.02)', 'rgba(255,255,255,0.05)']
                }
            },
            minorSplitLine: {
                lineStyle: {
                    color: 'rgba(255, 240, 225, 0.04)'
                }
            }
        };
    };

    // Dark overrides
    const overrides = {
        darkMode: true,
        backgroundColor: backgroundColor,
        loading: {
            textColor: '#c9d1d9'
        },
        axisPointer: {
            ...base.axisPointer,
            lineStyle: {
                color: 'rgba(255, 240, 225, 0.45)',
                type: 'dashed'
            },
            crossStyle: {
                color: 'rgba(255, 240, 225, 0.45)'
            },
            label: {
                color: '#fff'
            }
        },
        legend: {
            ...base.legend,
            textStyle: {
                color: contrastColor
            }
        },
        textStyle: {
            ...base.textStyle,
            color: contrastColor
        },
        title: {
            ...base.title,
            textStyle: {
                color: '#EEF1FA'
            },
            subtextStyle: {
                color: '#B9B8CE'
            }
        },
        toolbox: {
            ...base.toolbox,
            iconStyle: {
                borderColor: contrastColor
            }
        },
        tooltip: {
            backgroundColor: 'rgba(60, 44, 32, 0.6)',
            borderColor: 'rgba(255, 255, 255, 0.18)',
            textStyle: { color: '#ffffff' },
            extraCssText: 'border-radius: 16px; backdrop-filter: blur(22px) saturate(180%); -webkit-backdrop-filter: blur(22px) saturate(180%); box-shadow: 0 16px 32px -14px rgba(10, 5, 2, 0.75), inset 0 1px 0 rgba(255, 255, 255, 0.5);'
        },
        timeAxis: axisCommon(),
        logAxis: axisCommon(),
        valueAxis: axisCommon(),
        categoryAxis: (() => {
            const axis = axisCommon();
            axis.axisTick = {
                show: true
            };
            return axis;
        })(),
        grid: {
            ...base.grid,
            borderColor: 'rgba(255, 240, 225, 0.12)'
        },
        visualMap: {
            ...base.visualMap,
            textStyle: {
                color: contrastColor
            }
        },
        calendar: {
            ...base.calendar,
            itemStyle: {
                color: backgroundColor
            },
            dayLabel: {
                color: contrastColor
            },
            monthLabel: {
                color: contrastColor
            },
            yearLabel: {
                color: contrastColor
            }
        },
        dataZoom: {
            ...base.dataZoom,
            // A slim glass pill with an orange window and round handles.
            height: 18,
            borderColor: 'rgba(255, 240, 225, 0.18)',
            borderRadius: 9,
            backgroundColor: 'rgba(255, 240, 225, 0.08)',
            fillerColor: 'rgba(242, 103, 34, 0.28)',
            handleIcon: 'path://M512,512m-448,0a448,448,0,1,0,896,0a448,448,0,1,0,-896,0Z',
            handleSize: '110%',
            handleStyle: { color: 'rgba(255, 255, 255, 0.92)', borderColor: 'rgba(255, 240, 225, 0.4)' },
            moveHandleSize: 0,
            textStyle: { color: contrastColor },
            dataBackground: { lineStyle: { color: 'rgba(255, 240, 225, 0.25)' }, areaStyle: { color: 'rgba(255, 240, 225, 0.08)' } },
            selectedDataBackground: { lineStyle: { color: 'rgba(255, 170, 120, 0.6)' }, areaStyle: { color: 'rgba(242, 103, 34, 0.18)' } }
        }
    };

    return { ...base, ...overrides };
};

export { v5Theme, v5DarkTheme };
